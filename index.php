<?php
$API_KEY = "e76dbfc38ac073a94fe3def48f2e1e6c7d1b0649493d4529811706e50be5e6ba";

// Leagues
$leagues = [
    "Premier League" => 152,
    "La Liga" => 302,
    "Bundesliga" => 175,
    "Ligue 1" => 168
];

// Get date only
$date = isset($_GET['date']) ? $_GET['date'] : date("Y-m-d");

// ------------------- FUNCTIONS -------------------
function safe($arr,$key,$def="N/A"){ return isset($arr[$key])?$arr[$key]:$def; }

function estimate_btts($pred) {
    $hw = isset($pred['prob_HW']) ? intval($pred['prob_HW']) : 0;
    $aw = isset($pred['prob_AW']) ? intval($pred['prob_AW']) : 0;
    $d  = isset($pred['prob_D'])  ? intval($pred['prob_D'])  : 0;

    $btts_prob = min(95, max(5, intval(0.5*$hw + 0.5*$aw + 0.2*(100-$d))));
    $btts = $btts_prob >= 50 ? "BTTS Yes" : "BTTS No";

    return ['btts'=>$btts,'btts_pct'=>$btts_prob];
}

function predicted($pred){
    if(!$pred) return ["result"=>"N/A","result_pct"=>0,"overunder"=>"N/A","over_pct"=>0,"btts"=>"N/A","btts_pct"=>0];
    
    $hw = intval(safe($pred,'prob_HW',0));
    $aw = intval(safe($pred,'prob_AW',0));
    $d  = intval(safe($pred,'prob_D',0));

    $max = max($hw,$aw,$d);
    if($max==$hw) $res="Home Win";
    elseif($max==$aw) $res="Away Win";
    else $res="Draw";

    $over_prob = intval(($hw + $aw)/2);
    $overunder = $over_prob>=50?"Over 2.5":"Under 2.5";

    $btts_info = estimate_btts($pred);

    return ["result"=>$res,"result_pct"=>$max,
            "overunder"=>$overunder,"over_pct"=>$over_prob,
            "btts"=>$btts_info['btts'],"btts_pct"=>$btts_info['btts_pct']];
}

function actual($match){
    if(!isset($match['match_hometeam_score'], $match['match_awayteam_score'])) return ["result"=>"N/A","overunder"=>"N/A","btts"=>"N/A"];
    $h=intval($match['match_hometeam_score']); 
    $a=intval($match['match_awayteam_score']);
    $res = $h>$a?"Home Win":($a>$h?"Away Win":"Draw");
    $overunder = ($h+$a)>2.5?"Over 2.5":"Under 2.5";
    $btts = ($h>0 && $a>0)?"BTTS Yes":"BTTS No";
    return ["result"=>$res,"overunder"=>$overunder,"btts"=>$btts];
}

function last5_avg_goals($team_id, $league_id, $API_KEY){
    $url = "https://apiv3.apifootball.com/?action=get_events&league_id=$league_id&team_id=$team_id&APIkey=$API_KEY";
    $json = @file_get_contents($url);
    $matches = $json ? json_decode($json, true) : [];
    if(!is_array($matches)) return 0;

    $finished = [];
    foreach($matches as $m){
        if(isset($m['match_hometeam_score'],$m['match_awayteam_score']) &&
           $m['match_hometeam_score']!=="" && $m['match_awayteam_score']!=="") {
            $finished[] = $m;
        }
    }

    usort($finished,function($a,$b){
        return strtotime($b['match_date']) - strtotime($a['match_date']);
    });

    $last5 = array_slice($finished,0,5);
    if(empty($last5)) return 0;

    $total_goals = 0;
    foreach($last5 as $m){
        $h = intval($m['match_hometeam_score']);
        $a = intval($m['match_awayteam_score']);
        $total_goals += $h + $a;
    }

    return round($total_goals/count($last5),2);
}

// ------------------- FETCH ALL LEAGUES -------------------
$all_events = [];

foreach($leagues as $league_name => $league_id){
    $events_url = "https://apiv3.apifootball.com/?action=get_events&from=$date&to=$date&league_id=$league_id&APIkey=$API_KEY";
    $pred_url   = "https://apiv3.apifootball.com/?action=get_predictions&from=$date&to=$date&league_id=$league_id&APIkey=$API_KEY";

    $events_json = @file_get_contents($events_url);
    $events = $events_json ? json_decode($events_json, true) : [];

    $pred_json = @file_get_contents($pred_url);
    $predictions = $pred_json ? json_decode($pred_json, true) : [];

    $pred_map = [];
    foreach ($predictions as $p) {
        if (isset($p['match_id'])) $pred_map[$p['match_id']] = $p;
    }

    foreach($events as $m){
        $m['_league'] = $league_name;
        $m['_pred_map'] = $pred_map;
        $all_events[] = $m;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Football Predictions vs Results</title>
<style>
body{font-family:Arial;background:#f4f4f4;padding:20px;}
table{border-collapse:collapse;width:100%;background:#fff;}
th,td{padding:10px;border:1px solid #ddd;text-align:center;}
th{background:#333;color:#fff;}
.correct{background:#c6efce;} 
.wrong{background:#ffc7ce;}   
.high{background:#cfe2f3;}    
.mostlikely-correct{background:#a8d08d;} 
.mostlikely-wrong{background:#bfbfbf;}   
</style>
</head>
<body>

<h2>Matches on <?php echo htmlspecialchars($date); ?></h2>

<form method="get">
<label>Date:</label>
<input type="date" name="date" value="<?php echo htmlspecialchars($date);?>">
<button type="submit">Show</button>
</form>

<table>
<tr>
<th>League</th>
<th>Home Team</th>
<th>Away Team</th>
<th>Predicted</th>
<th>Actual</th>
<th>Most Likely</th>
</tr>

<?php
if(!empty($all_events)){
    foreach($all_events as $m){
        $mid = safe($m,'match_id');
        $pred_map = $m['_pred_map'];
        $pred = isset($pred_map[$mid])?$pred_map[$mid]:null;

        $home = safe($m,'match_hometeam_name');
        $away = safe($m,'match_awayteam_name');
        $pred_info = predicted($pred);
        $act_info = actual($m);

        $home_id = safe($m,'match_hometeam_id',0);
        $away_id = safe($m,'match_awayteam_id',0);

        $home_avg = last5_avg_goals($home_id, $league_id, $API_KEY);
        $away_avg = last5_avg_goals($away_id, $league_id, $API_KEY);

        $candidates = [
            ["label"=>$pred_info['result'], "pct"=>$pred_info['result_pct'], "actual"=>$act_info['result']],
            ["label"=>$pred_info['overunder'], "pct"=>$pred_info['over_pct'], "actual"=>$act_info['overunder']],
            ["label"=>$pred_info['btts'], "pct"=>$pred_info['btts_pct'], "actual"=>$act_info['btts']]
        ];
        usort($candidates, function($a,$b){ return $b['pct'] - $a['pct']; });
        $most = $candidates[0];
        $ml_class = ($most['label'] == $most['actual']) ? "mostlikely-correct" : "mostlikely-wrong";

        echo "<tr>";
        echo "<td>{$m['_league']}</td>";
        echo "<td>$home (avg goals: $home_avg)</td>";
        echo "<td>$away (avg goals: $away_avg)</td>";
        echo "<td>{$pred_info['result']} {$pred_info['result_pct']}% | {$pred_info['overunder']} {$pred_info['over_pct']}% | {$pred_info['btts']} {$pred_info['btts_pct']}%</td>";
        echo "<td>{$act_info['result']} | {$act_info['overunder']} | {$act_info['btts']}</td>";
        echo "<td class='$ml_class'>{$most['label']} ({$most['pct']}%)</td>";
        echo "</tr>";
    }
}else{
    echo "<tr><td colspan='6'>No matches found</td></tr>";
}
?>
</table>
</body>
</html>
