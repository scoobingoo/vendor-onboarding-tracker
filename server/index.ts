import { createApp } from './app.js';
import { openDatabase } from './db.js';
import { isEmpty, seed } from './seed.js';
import { config } from './config.js';

const db = openDatabase(config.dbPath);
if (isEmpty(db)) {
  seed(db, config.clock());
  console.log(`Seeded demo data into ${config.dbPath}`);
}

createApp({ db, clock: config.clock, staticDir: config.staticDir }).listen(config.port, () => {
  console.log(`API listening on http://localhost:${config.port}`);
});
