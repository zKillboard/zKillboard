db.information.updateMany({name:{$type:"string"},$expr:{$ne:[{$toLower:"$name"},{$toLower:"$l_name"}]}},[{$set:{l_name:{$toLower:"$name"}}}]);
