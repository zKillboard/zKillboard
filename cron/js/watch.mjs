function out(doc) {
  const formatted = new Date(doc.epoch).toISOString().replace('T', ' ').slice(0, 19);
  doc.server = doc.server.padStart(13, ' ' );
  print(`${doc.server} ${formatted} > ${doc.text}`);
}

// Display last 50 entries
db.cronlog.find().sort({epoch: -1}).limit(50).toArray().sort((a,b)=>a.epoch - b.epoch).forEach(doc => out(doc))

// Try to use change streams if replication is available, otherwise fall back to polling
let useChangeStreams = false;
try {
  const cursor = db.cronlog.watch([{ $match: { operationType: "insert" } }]);
  useChangeStreams = true;
  
  print("Using change streams (replica set detected)");
  while (true) {
    const change = cursor.tryNext();
    if (change) out(change.fullDocument);
    else sleep(100); // sleep to avoid tight loop
  }
} catch (e) {
  if (e.codeName === 'CommandNotSupported' || e.code === 40573) {
    print("Change streams not supported (no replica set), falling back to polling...");
    
    // Polling fallback - get last seen ID
    let lastId = db.cronlog.find().sort({_id: -1}).limit(1).toArray()[0]?._id;
    
    while (true) {
      const newDocs = db.cronlog.find({_id: {$gt: lastId}}).sort({_id: 1}).toArray();
      newDocs.forEach(doc => {
        out(doc);
        lastId = doc._id;
      });
      sleep(1000); // Poll every second
    }
  } else {
    throw e; // Re-throw if it's a different error
  }
}
