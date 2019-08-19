<?php @session_start();
// Controls the "current award" kiosk display
require_once('inc/data.inc');
require_once('inc/banner.inc');
require_once('inc/authorize.inc');
require_once('inc/schema_version.inc');
require_permission(PRESENT_AWARDS_PERMISSION);
?><!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
<title>Awards Presentation Dashboard</title><?php require('inc/stylesheet.inc'); ?>
<script type="text/javascript" src="js/jquery.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.10.4.min.js"></script>
<script type="text/javascript" src="js/dashboard-ajax.js"></script>
<script type="text/javascript" src="js/mobile-init.js"></script>
<script type="text/javascript" src="js/jquery.mobile-1.4.2.min.js"></script>
<script type="text/javascript" src="js/awards-presentation.js"></script>
<?php
    try {
      $nkiosks = read_single_value('SELECT COUNT(*) FROM Kiosks'
                                   .' WHERE page LIKE \'%award%present%\'', array());
    } catch (PDOException $p) {
      if ($p->getCode() == '42S02') {
        create_kiosk_table();
      }
      $nkiosks = 0;
    }

    if ($nkiosks == 0) {
      echo '<script type="text/javascript">'."\n";
      echo '$(window).load(function() {'."\n";
      echo '  setTimeout(function() {'."\n";
      echo '  alert("NOTE: There are NO kiosks ready for award presentation."+'."\n";
      echo '        "  Selections on this dashboard won\'t have any observable effect.");'."\n";
      echo '}, 500); });'."\n";
      echo '</script>'."\n";
    }
?>
<link rel="stylesheet" type="text/css" href="css/jquery.mobile-1.4.2.css"/>
<link rel="stylesheet" type="text/css" href="css/awards-presentation.css"/>
</head>
<body>
<?php make_banner('Awards Presentation');

require_once('inc/standings.inc');
require_once('inc/ordinals.inc');
require_once('inc/awards.inc');

$use_subgroups = read_raceinfo_boolean('use-subgroups');
$n_pack_trophies = read_raceinfo('n-pack-trophies', 3);
$n_den_trophies = read_raceinfo('n-den-trophies', 3);
$n_rank_trophies = read_raceinfo('n-rank-trophies', 0);

list($classes, $classseq, $ranks, $rankseq) = classes_and_ranks();

// A bin_key is a string:
//  'p' (overall group)
//  'c' + classid
//  'r' + rankid
//
// A bin is an array with keys:
//  name   => bin name (string)
//  awards => array of awards, each award being an array of:
//    bin_key?
//    classid
//    rankid
//    awardkey => string that award.current action understands, for presentation
//    score (e.g., the average time or average place), for detecting ties
//    awardname
//    awardtype
//    awardtypeid
//    sort
//    firstname
//    lastname
//    carnumber
//    carname

// Not sure what's going on with Speed Trophy awardtype in GPRM; it's not
// offered as a choice for explicit awards.  "Speed Standings" is a choice for
// the Awards page, though.

$awards = array();
// $speed_awards_in_bin maps bin_key to { score, place },
// 'count'
// 'score' is the score of the most recent award, and
// 'place' is 
$speed_awards_in_bin = array();

// The highest 'sort' value for speed awards; used as an offset for the
// non-speed awards.
$max_speed_sort = 0;

function bin_key($classid, $rankid) {
  if (!isset($classid)) {
    return 'p';
  } else if (!isset($rankid)) {
    return 'c'.$classid;
  } else {
    return 'r'.$rankid;
  }
}

function get_racer_details($racerid) {
  return read_single_row('SELECT racerid, carnumber, lastname, firstname, carname'
                         .' FROM RegistrationInfo'
                         .' WHERE racerid = :racerid',
                         array(':racerid' => $racerid),
                         PDO::FETCH_ASSOC);
}

function add_speed_group($n, $classid, $rankid, $label, &$standings) {
  global $awards, $max_speed_sort;
  $finishers = top_finishers(@$classid, @$rankid, $standings);
  for ($p = 0; $p < $n; ++$p) {
    if (!isset($finishers[$p])) {
      continue;
    }
    for ($i = 0; $i < count($finishers[$p]); ++$i) {
      $racerid = $finishers[$p][$i];
      // This is an approximation, assumes no more than 9 ranks per class, no more
      // than 10 speed trophies per rank, and no more than 10 speed trophies per
      // class.
      $sort = (isset($classid) ? $classid : 0) * 100 + (isset($rankid) ? $rankid : 0) * 10 + $p;
      if ($sort > $max_speed_sort) {
        $max_speed_sort = $sort;
      }
      $row = get_racer_details($racerid);
      $awards[] = array('bin_key' => bin_key(@$classid, @$rankid),
                        'classid' => @$classid,
                        'rankid' => @$rankid,
                        'awardkey' => 'speed-'.(1 + $p)
                            .(count($finishers[$p]) > 1 ? chr(ord('a') + $i) : '')
                            .(isset($classid) ? '-'.$classid : '')
                            .(isset($rankid) ? '-'.$rankid : ''),
                        'awardname' => nth_fastest(1 + $p, $label),
                        // TODO Hard-wired constants, ugh
                        'awardtype' => 'Speed Trophy',
                        'awardtypeid' => 5,
                        'sort' => $sort,
                        'firstname' => $row['firstname'],
                        'lastname' => $row['lastname'],
                        'carnumber' => $row['carnumber'],
                        'carname' => $row['carname']);
    }
  }
}

$standings = final_standings();
add_speed_group($n_pack_trophies, null, null, supergroup_label(), $standings);

foreach ($classseq as $c) {
  add_speed_group($n_den_trophies, $c, null, $classes[$c]['class'], $standings);
}
foreach ($rankseq as $r) {
  add_speed_group($n_rank_trophies, $c, $r, $ranks[$r]['rank'], $standings);
}


foreach ($db->query('SELECT awardid, awardname, awardtype,'
                    .' Awards.awardtypeid, Awards.classid, Awards.rankid, sort,'
                    .' firstname, lastname, carnumber, carname'
                    .' FROM '.inner_join('Awards', 'AwardTypes',
                                         'Awards.awardtypeid = AwardTypes.awardtypeid',
                                         'RegistrationInfo',
                                         'Awards.racerid = RegistrationInfo.racerid')
                    .' ORDER BY sort, lastname, firstname') as $row) {
  $awards[] =
      array('bin_key' => bin_key(@$row['classid'], @$row['rankid']),
            'classid' => @$row['classid'],
            'rankid' => @$row['rankid'],
            'awardkey' => 'award-'.$row['awardid'],
            'awardname' => $row['awardname'],
            'awardtype' => $row['awardtype'],
            'awardtypeid' => $row['awardtypeid'],
            'sort' => $max_speed_sort + $row['sort'],
            'firstname' => $row['firstname'],
            'lastname' => $row['lastname'],
            'carnumber' => $row['carnumber'],
            'carname' => $row['carname']);
}

function compare_by_sort(&$lhs, &$rhs) {
  if ($lhs['sort'] != $rhs['sort']) {
    return $lhs['sort'] < $rhs['sort'] ? -1 : 1;
  }
  if ($lhs['lastname'] != $rhs['lastname']) {
    return $lhs['lastname'] < $rhs['lastname'] ? -1 : 1;
  }
  if ($lhs['firstname'] != $rhs['firstname']) {
    return $lhs['firstname'] < $rhs['firstname'] ? -1 : 1;
  }
  return 0;
}

usort($awards, 'compare_by_sort');
?>

<div class="block_buttons">

<div class="center-select">
    <select id="awardtype-select">
        <option selected="Selected">All Awards</option>
        <?php
        foreach ($db->query('SELECT awardtypeid, awardtype FROM AwardTypes ORDER BY awardtype') as $atype) {
          echo '<option data-awardtypeid="'.$atype['awardtypeid'].'">'
              .htmlspecialchars($atype['awardtype'], ENT_QUOTES, 'UTF-8')
              .'</option>'."\n";
        }
        ?>
    </select>
</div>

<div class="center-select">
    <select id="group-select">
      <option selected="Selected">All</option>
      <option data-supergroup="1"><?php echo supergroup_label(); ?></option>
        <?php
        $classid = -1;
        foreach ($rankseq as $rankid) {
          $rank = $ranks[$rankid];
          if ($rank['classid'] != $classid) {
              $classid = $rank['classid'];
              echo '<option data-classid="'.$classid.'">'
               .htmlspecialchars($rank['class'], ENT_QUOTES, 'UTF-8')
               .'</option>'."\n";
          }
          if ($use_subgroups) {
            echo '<option data-classid="'.$classid.'" data-rankid="'.$rank['rankid'].'">'
                 .htmlspecialchars($rank['rank'], ENT_QUOTES, 'UTF-8')
                 .'</option>'."\n";
          }
        }
        ?>
    </select>
</div>

<div class="listview">
<ul data-role="listview" class="ui-listview">
<?php

foreach ($awards as &$row) {
   $classid = isset($row['classid']) ? $row['classid'] : 0;
   $rankid = (isset($row['rankid']) && $use_subgroups) ? $row['rankid'] : 0;
   echo '<li class="ui-btn ui-btn-icon-right ui-icon-carat-r'.($row['awardtypeid'] == AD_HOC_AWARDTYPEID ? ' adhoc' : '').'"'
        .' onclick="on_choose_award(this);"'
        .' data-awardkey="'.$row['awardkey'].'"'
        .' data-awardtypeid="'.$row['awardtypeid'].'"'
        .' data-classid="'.$classid.'"'
        .' data-rankid="'.$rankid.'"'
        .' data-awardname="'.htmlspecialchars($row['awardname'], ENT_QUOTES, 'UTF-8').'"'
        .' data-recipient="'.htmlspecialchars($row['firstname'].' '.$row['lastname'],
                                              ENT_QUOTES, 'UTF-8').'"'
        .' data-carnumber="'.$row['carnumber'].'"'
        .' data-carname="'.htmlspecialchars($row['carname'], ENT_QUOTES, 'UTF-8').'"'
        .' data-class="'.($classid ?
                          htmlspecialchars($classes[$classid]['class'], ENT_QUOTES, 'UTF-8') : '').'"'
        .' data-rank="'.($rankid ?
                          htmlspecialchars($ranks[$rankid]['rank'], ENT_QUOTES, 'UTF-8') : '').'"'
        .'>';
    echo '<span>'.htmlspecialchars($row['awardname'], ENT_QUOTES, 'UTF-8').'</span>';
    echo '<p><strong>'.$row['carnumber'].':</strong> ';
    echo htmlspecialchars($row['firstname'].' '.$row['lastname'], ENT_QUOTES, 'UTF-8');
    echo '</p>';
    echo '</li>';
}
?>
</ul>
</div>

<div class="presenter">

<div id="kiosk-summary">
<?php
        // TODO Kiosks table may not exist
    $nkiosks = read_single_value('SELECT COUNT(*) FROM Kiosks'
                                 .' WHERE page LIKE \'%award%present%\'', array());
    if ($nkiosks == 0) {
      echo '<h3>NOTE:</h3>';
      echo '<h3>There are NO kiosks ready for award presentation.</h3>';
      echo '<p class="moot">Selections on this dashboard won\'t have any observable effect.</p>';
      echo '<p class="moot">Visit the <a href="kiosk-dashboard.php">Kiosk Dashboard</a> to assign displays.</p>';
    }
?>
</div>

<h3 id="awardname"></h3>

<h3 id="classname"></h3>
<h3 id="rankname"></h3>

<h3 id="recipient"></h3>
<p id="carnumber" class="detail"></p>
<p id="carname" class="detail"></p>

<div class="presenter-inner hidden">
  <input type="checkbox" data-role="flipswitch"
        id="reveal-checkbox"
        data-on-text="Showing"
        data-off-text="Hidden"
        onchange="on_reveal();"/>
</div>

<div class="reset-footer block_buttons">
    <input type="button" data-enhanced="true" value="Clear" onclick="on_clear_awards()"/>
</div>

</div>

</div>
</body>
</html>
