<?php

namespace App\Http\Requests;

use App\Domain\Stage;
use App\Domain\VendorFilters;
use App\Domain\Workflow;
use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_stage' => ['required', Rule::enum(Stage::class)],
            // The stage the user saw when they decided; used to detect concurrent edits.
            'expected_from_stage' => ['required', Rule::enum(Stage::class)],
            // Already trimmed by the TrimStrings middleware; blank becomes null.
            'note' => ['nullable', 'string', 'max:'.Workflow::MAX_NOTE_LENGTH],
        ];
    }

    public function toStage(): Stage
    {
        return Stage::from($this->validated('to_stage'));
    }

    public function expectedFromStage(): Stage
    {
        return Stage::from($this->validated('expected_from_stage'));
    }

    public function note(): ?string
    {
        return $this->validated('note');
    }

    /** Back to the vendor's panel, keeping the list filters the user had. */
    public function vendorUrl(): string
    {
        /** @var Vendor $vendor */
        $vendor = $this->route('vendor');

        return route('vendors.show', ['vendor' => $vendor] + VendorFilters::fromQuery($this->query())->toQuery());
    }

    protected function getRedirectUrl(): string
    {
        return $this->vendorUrl();
    }
}
