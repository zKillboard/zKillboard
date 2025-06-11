
function reportCollectionSizes() {
  cls();
  let total = { d: 0, i: 0, t: 0, c: 0 };

  print("Collection Name".padEnd(30) +
        "Data Size (GiB)".padStart(18) +
        "Index Size (GiB)".padStart(20) +
        "Total Size (GiB)".padStart(20) +
        "Compressed (GiB)".padStart(20));
  print("-".repeat(108));

  db.getCollectionNames()
    .map(n => {
      const s = db.getCollection(n).stats();
      const d = s.size,
            i = s.totalIndexSize,
            t = d + i,
            c = Math.max(0, d - s.storageSize);
      return {
        n: s.ns.split(".").slice(1).join("."), // remove db name prefix
        d, i, t, c
      };
    })
    .filter(s => (s.t / 1073741824).toFixed(2) !== "0.00")
    .sort((a, b) => a.t - b.t)
    .forEach(s => {
      total.d += s.d;
      total.i += s.i;
      total.t += s.t;
      total.c += s.c;
      print(s.n.padEnd(30) +
            (s.d / 1073741824).toFixed(2).padStart(18) +
            (s.i / 1073741824).toFixed(2).padStart(20) +
            (s.t / 1073741824).toFixed(2).padStart(20) +
            (s.c / 1073741824).toFixed(2).padStart(20));
    });

  print("-".repeat(108));
  print("TOTAL".padEnd(30) +
        (total.d / 1073741824).toFixed(2).padStart(18) +
        (total.i / 1073741824).toFixed(2).padStart(20) +
        (total.t / 1073741824).toFixed(2).padStart(20) +
        (total.c / 1073741824).toFixed(2).padStart(20));
}
reportCollectionSizes();
