db.information.updateMany({type:{$ne:'typeID'},name:{$type:"string"},$expr:{$ne:[{$toLower:"$name"},{$toLower:"$l_name"}]}},[{$set:{l_name:{$toLower:"$name"}}}]);
db.information.updateMany({type:'typeID',categoryID:6,name:{$type:"string"},$expr:{$ne:[{$toLower:"$name"},{$toLower:"$l_name"}]}},[{$set:{l_name:{$toLower:"$name"}}}]);
