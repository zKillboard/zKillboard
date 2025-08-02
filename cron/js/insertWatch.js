// watchInserts.js

const { MongoClient } = require('mongodb');

const dbName = 'zkillboard';
const collectionName = 'information';

async function runWatcher() {
  const client = new MongoClient(process.env.mongoConnString);

  try {
    await client.connect();
    console.log(`Connected to MongoDB, watching '${collectionName}' for inserts...`);

    const collection = client.db(dbName).collection(collectionName);

    const pipeline = [
      { $match: { operationType: 'insert' } }
    ];

    const changeStream = collection.watch(pipeline);

    changeStream.on('change', (change) => {
      console.log('New document inserted:', change.fullDocument);
    });

  } catch (err) {
    console.error('Watcher error:', err);
  }
}

runWatcher();

