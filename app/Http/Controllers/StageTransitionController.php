<?php

namespace App\Http\Controllers;

use App\Domain\ChangeVendorStage;
use App\Domain\Exceptions\InvalidTransitionException;
use App\Domain\Exceptions\StaleVendorException;
use App\Http\Requests\ChangeStageRequest;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;

class StageTransitionController extends Controller
{
    /**
     * "Your view is stale" and "that move isn't allowed" need different
     * handling: a stale view gets a warning and a fresh form (the user has to
     * look again before deciding), while a rule violation keeps what the user
     * typed and explains the rule.
     */
    public function store(ChangeStageRequest $request, Vendor $vendor, ChangeVendorStage $changeStage): RedirectResponse
    {
        $back = redirect()->to($request->vendorUrl());

        try {
            $changeStage->handle(
                $vendor,
                $request->toStage(),
                $request->expectedFromStage(),
                $request->note(),
                $request->user(),
            );
        } catch (StaleVendorException $e) {
            $current = $e->current->load('latestTransition.changedBy');
            $by = $current->latestTransition?->changedBy?->display_name ?? 'someone else';

            return $back->with('warning', sprintf(
                'Not saved: %s already moved this vendor to "%s". The view has been refreshed. Please review before trying again.',
                $by,
                $current->stage->label(),
            ));
        } catch (InvalidTransitionException $e) {
            return $back->withErrors(['transition' => $e->getMessage()])->withInput();
        }

        return $back->with('success', sprintf('Moved to "%s".', $request->toStage()->label()));
    }
}
