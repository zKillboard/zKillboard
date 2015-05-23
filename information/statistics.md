# Statistics
<hr/>
### Kills
{kills} kills processed ({percentage}%)<br>
######*These numbers are updated hourly...*

### API
Number of API calls and their result in the last hour.<br/>

{apitable}

### Points
```
Calculation:
        $vicpoints = Points::getPoints($victim["groupID"]);
        $vicpoints += $kill["total_price"] / 10000000;
        $maxpoints = round($vicpoints * 1.2);

        $invpoints = 0;
        foreach ($involved as $inv)
        {
                $invpoints += Points::getPoints($inv["groupID"]);
        }

        $gankfactor = $vicpoints / ($vicpoints + $invpoints);
        $points = ceil($vicpoints * ($gankfactor / 0.75));
        if ($points > $maxpoints) $points = $maxpoints;
        $points = round($points, 0);
```

### Point System
{pointsystem}
