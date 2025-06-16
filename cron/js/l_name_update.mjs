db.information.updateMany({name:{$type:"string"},$expr:{$ne:[{$toLower:"$name"},{$toLower:"$l_name"}]}},[{$set:{l_name:{$toLower:"$name"}}}]);

db.cronlog.insertOne({epoch: new Date(), 'server' : 'primary', 'source' : 'l_name update', 'text' : 'l_name has been updated'});
