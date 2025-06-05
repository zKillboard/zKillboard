function out(doc) {
  const formatted = new Date(doc.epoch).toISOString().replace('T', ' ').slice(0, 19);
  print(`${formatted} > ${doc.text}`);
}

const cursor = db.cronlog.watch([{ $match: { operationType: "insert" } }]);

db.cronlog.find().sort({epoch: -1}).limit(50).toArray().sort((a,b)=>a.epoch - b.epoch).forEach(doc => out(doc))

while (true) {
  const change = cursor.tryNext();
  if (change) out(change.fullDocument);
  else sleep(100); // sleep to avoid tight loop
}
