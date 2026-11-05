/** `npm run seed`: wipes the local database and reseeds the demo data. */
import { rmSync } from 'node:fs';
import { openDatabase } from './db.js';
import { seed } from './seed.js';
import { config } from './config.js';

for (const suffix of ['', '-wal', '-shm']) {
  rmSync(`${config.dbPath}${suffix}`, { force: true });
}
const db = openDatabase(config.dbPath);
seed(db, config.clock());
db.close();
console.log(`Reset and seeded ${config.dbPath}`);
