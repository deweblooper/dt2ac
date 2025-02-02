<?php

/*
* This library is free software, and it is part of the Active Collab SDK project. Check LICENSE for details.
*
* (c) A51 doo <info@activecollab.com>
* 
* Zdroj & Howto: https://github.com/activecollab/activecollab-feather-sdk
* API DOC: https://developers.activecollab.com/api-documentation/index.html
*/


require_once __DIR__ . '/vendor/autoload.php';

$html_content = ''; // výstupný obsah
$open_display = 0; // kontrolné výpisy
$logged_in = null;

session_start();


// Inštancia SDK pre prihláseného (používa sa opakovane)
if (isset($_SESSION['userLogged']) && $_SESSION['userLogged'] != '') {

	$authenticator = new \ActiveCollab\SDK\Authenticator\SelfHosted(
		'ACME Inc', 
		'My Awesome Application', 
		$_SESSION['userLogged']['email'], 
		$_SESSION['userLogged']['pass'],
		$_SESSION['userLogged']['ac_url']
	);

	$token = $authenticator->issueToken();
    $client = new \ActiveCollab\SDK\Client($token);
    $users = $client->get('users')->getJson();
	//$user = $authenticator->getUser();

	$logged_in = 1;
}


## Zobrazenie dát importovaných z CSV (prihlásený užívateľ)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['uploaded_file'])) {

	$file = $_FILES['uploaded_file']['tmp_name'];
	$file_content = file_get_contents($file);

	// Vytiahnuť celý zoznam projektov užívateľa
    $user_projects_list = $client->get('users/'.$_SESSION['userLogged']['id'].'/projects')->getJson();
	
	// vytiahnutie dát z uloženého súboru (používame DeskTime export za mesiac, nie iný)
	$csv_row = '';
	$array_data = [];

    // z obsahu súboru vloženého cez HTML formulár
	if(isset($file_content)) {

		$file_rows = explode("\n", $file_content);
		// hlavičky
		$first_rows = array_shift($file_rows);
		$headers = str_replace('"', '', explode(",", $first_rows));
		// zvyšný obsah bez prvého riadku
		foreach ($file_rows as $csv_row) {
			if ( ($csv_row === false) || ($csv_row == "") ) {
				continue;
			}
			$array_data[] = $csv_row;
		}
		
	} else {

		$html_content .= '
            <div class="col-md-6 col-md-push-3">
                <div class="alert alert-warning" role="alert">
                    <span class="glyphicon glyphicon-warning-sign"></span>&nbsp;&nbsp;<strong>Chyba!</strong> Súbor sa nepodarilo načítať.
                </div>
            </div>
            <div class="col-md-4 col-md-push-4">
                <div class="text-center">
                    <a href="" class="btn btn-lg btn-primary" type="submit">OK</a>
                </div>
            </div>';

	}
	
	// správne vybratie prvkov s ohľadom na úvodzovky
	function parseCSVLine($line) {
		preg_match_all('/(?<=^|,)"([^"]*)"|(?<=,|^)([^,]*)/', $line, $matches);
		$result = [];
		foreach ($matches[1] as $key => $match) {
			$result[] = $match !== '' ? $match : $matches[2][$key];
		}
		return $result;
	}


	$csv_data = array_map('parseCSVLine', $array_data);
	
	// napárovanie obsahu a ošetrených hlavičiek
	foreach ($csv_data as $kt => $task) {
		foreach ($task as $kv => $value) {
			$csv_data_parsed[$kt][trim($headers[$kv])] = $value;
		}
	}
	
	// výstupné data: Projekty + IDs
	/*
	 *	Array
	 *	(
	 *		[3] => Filesmann Custom Project
	 *		[10] => Extended Support
	 *	)
	*/
    $ac_projects = [];
	foreach ($user_projects_list as $project_data) {
		$ac_projects[$project_data['id']] = $project_data['name'];
	}
	
	
	// výstupné data: Tasky pod projektami (project_id a project_name sú len pre kontrolné info)
	/*
	 *	Array
	 *	(
	 *		[3] => Array
	 *			(
	 *				[project_id] => 3
	 *				[project_name] => Filesmann Custom Project
	 *				[3] => #1234: Quicktime task
	 *				[10] => #5678: Easy cheesy
	 *				...
	 *			)
	 *
	 *		[10] => Array
	 *			(
	 *				[project_id] => 10
	 *				[project_name] => Extended Support
	 *				[17] => #789: server issues
	 *				[24] => #123: connection issues
	 *				...
	 *			)
	 *
	 *	)
	*/
    $ac_tasks = [];
	foreach ($ac_projects as $id_project => $project) {
		$project_tasks = $client->get('projects/'.$id_project.'/tasks')->getJson();
		$ac_tasks[$id_project]['project_id'] = $id_project;
		$ac_tasks[$id_project]['project_name'] = $project;
		foreach ($project_tasks['tasks'] as $kt => $task_data) {
			$ac_tasks[$id_project][$task_data['id']] = "#" . $task_data['task_number'] . ": " . $task_data['name'];
		}
	}
	
	// posledný prac.deň v mesiaci pre priradenie časov projektov a taskov
	function getLastBusyDay($dt_date) {
	
		$date = DateTime::createFromFormat('F, Y', $dt_date);
		if (!$date) {
			$err = "Neplatný formát dátumu";
		} else {
			$err = 0;
		}
		$date->modify('last day of this month');
		while (in_array($date->format('N'), [6, 7])) { // 6 = so., 7 = ne.
			$date->modify('-1 day');
		}
		return [
			'last_date' => $date->format('Y-m-d'),
			'err' => $err,
		];
	}
	

	// Vytiahnuť default job-type projektu s ID (potrebné definovať pri vkladanie časov)
	$project_job_types = $client->get('job-types')->getJson();
	foreach ($project_job_types as $job_type) {
		if ($job_type['is_default'] != 1) {
			continue;
		} else {
			$_SESSION['job_type_project'] = $job_type['id'];
		}
	}
	
	
    ## Skompletovanie dát pre export do AC
	$acdt_data = [];    

	foreach ($csv_data_parsed as $kc => $csv_entry) {
	
        // výstupné id projektu (výrazy ošetrené od medzier a veľkosti písmen pre nekompatibilitu názvov)
		$in_projects = array_map(function($v) {
            return str_replace(" ", "", $v);
        }, $ac_projects);
		$search_project_id = array_search(
            strtolower(str_replace(" ", "", $csv_entry['Project'])), 
            array_map('strtolower', $in_projects)
        );


		$acdt_data[$kc]['Date'] = $csv_entry['Date'];
		$LastBusyDay = getLastBusyDay($csv_entry['Date']);
		$acdt_data[$kc]['date_last'] = ($LastBusyDay['err'] == 0) ? $LastBusyDay['last_date'] : $LastBusyDay['err'];
		
		$acdt_data[$kc]['Project'] = $csv_entry['Project'];
		$acdt_data[$kc]['id_project'] = $search_project_id;

		$acdt_data[$kc]['Task'] = (!empty($csv_entry['Task'])) ? $csv_entry['Task'] : null;
		$acdt_data[$kc]['id_task'] = (!empty($csv_entry['Task'])) ? ( array_search( $csv_entry['Task'], ((!empty($ac_tasks[$search_project_id])) ? $ac_tasks[$search_project_id] : []) ) ) : null;

		$acdt_data[$kc]['Total time'] = $csv_entry['Total time'];
		
		// konvertovanie hodín z 60 do 10 sustavy
		list($h, $m, $s) = explode(':', $csv_entry['Total time']);
		$decimal_time = $h + ($m / 60) + ($s / 3600);
		$acdt_data[$kc]['time'] = round($decimal_time, 2);	// zaokrúhlené na desatiny
		$acdt_data[$kc]['time_q'] = ceil($decimal_time * 4) / 4;	// zaokrúhlené na štvrťhodiny nahor

	}
	// !! $acdt_data je výstupný array pre export do AC !!
		$_SESSION['acdt_data'] = $acdt_data;



	//** Výstupné HTML **
	if ($acdt_data) {
		$html_content .= '
				<div class="col-md-12">
					<h3>DeskTime export</h3>
					<hr>
					<div class="table-responsive">
						<table class="table table-striped table-hover">
							<thead>
								<tr>
									<th>Date</th>
									<th>Project</th>
									<th>Task</th>
									<th>Total time</th>
									<th class="info">date_last</th>
									<th class="info">id_project</th>
									<th class="info">id_task</th>
									<th class="info">time&nbsp;<span class="label label-info" data-toggle="tooltip" title="Čas zaokrúhľovaný na desatiny" style="cursor:pointer;">i</span></th>
									<th class="info">time_q&nbsp;<span class="label label-info" data-toggle="tooltip" title="Čas zaokrúhľovaný na 15min nahor (tento sa odosiela)" style="cursor:pointer;">i</span></th>
								</tr>
							</thead>
							<tbody>
		';

		$imported_issues = 0;

		foreach ($acdt_data as $acdt) {
			$html_content .= "<tr>";
			$html_content .= "<td>" . $acdt['Date'] . "</td>";
			$html_content .= "<td>" . $acdt['Project'] . "</td>";
			$html_content .= "<td>" . $acdt['Task'] . "</td>";
			$html_content .= "<td>" . $acdt['Total time'] . "</td>";
			$html_content .= '<td class="info">' . $acdt['date_last'] . "</td>";
			if (!empty($acdt['id_project'])) {
				$html_content .= '<td class="info">' . $acdt['id_project'] . "</td>";
			} else {
				$html_content .= '<td class="info"><span class="badge" data-toggle="tooltip" title="Chýba id projektu, je to OK? Čas sa neuloží." style="cursor:pointer; background-color: #ff0000; font-weight: 900;">!</span></td>';
				$imported_issues++;
			}
			if (!empty($acdt['id_task'])) {
				$html_content .= '<td class="info">' . $acdt['id_task'] . "</td>";
			} else {
				$html_content .= '<td class="info"><span class="badge" data-toggle="tooltip" title="Chýba id tasku, je to OK? Čas sa uloží k Projektu" style="cursor:pointer; background-color: #ff0000; font-weight: 900;">!</span></td>';
				$imported_issues++;
			}
			$html_content .= '<td class="info">' . $acdt['time'] . "</td>";
			$html_content .= '<td class="info">' . $acdt['time_q'] . "</td>";
			$html_content .= "</tr>";
		}

		if ($imported_issues > 0) {
			$open_display = 1; // zobrazí $acdt_data pre kontrolu chýb v obsahu
		}

		$html_content .= "<tr>";
		$html_content .= '<td colspan="4" class="text-left"><span class="glyphicon glyphicon-download-alt"></span> <small>Toto sú časy importované zo súboru CSV z DeskTime</small></td>';
		$html_content .= '<td colspan="5" class="text-right"><small>Takto spracované sa to bude <strong>exportovať do ActiveCollab</strong></small> <span class="glyphicon glyphicon-share-alt"></span></td>';
		$html_content .= "</tr>";


		$html_content .= '
							</tbody>
						</table>
					</div>
				</div>
				<div class="form-group col-md-12 col-md-push-0" style="margin-top: 20px;">
					<form action="" method="post">
						<a href="" class="btn btn-default pull-left">Cancel</a>
						<button type="submit" class="btn btn-success pull-right" value="1" name="submitExport">Exportovať</button>
					</form>
				</div>
		';	
	}

## Export dát časov do A.C. (prihlásený užívateľ)
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submitExport'])) {
	
	$export_failed = 0;
	$exported_table = '';

    if (isset($_SESSION['acdt_data'])) {

        $acdt_data = $_SESSION['acdt_data'];

		$exported_table_rows = '';
		$test_export = [];

		foreach ($acdt_data as $ke => $item_data) {

			if(isset($item_data['id_task']) && $item_data['id_task'] != null) {
				
				// pridať čas tasku v projekte
				$test_export[$ke] = [
					'export'=> "taskovy",
					'item_data' => [
						'id_project' => $item_data['id_project'],
						'id_task' => $item_data['id_task'],
						'value' => $item_data['time_q'],
						'user_id' => $_SESSION['userLogged']['id'],
						'job_type_id' => $_SESSION['job_type_project'],
						'record_date' => $item_data['date_last'],
						'billable_status' => 1,
					],
				];

				try {
					$time_add = $client->post('projects/'. $item_data['id_project'] .'/time-records', [
						'task_id' => $item_data['id_task'],
						'value' => $item_data['time_q'],
						'user_id' => $_SESSION['userLogged']['id'],
						'job_type_id' => $_SESSION['job_type_project'],
						'record_date' => $item_data['date_last'],
						'billable_status' => 1
					])->getJson();
				} catch(AppException $e) {
					$time_add = $e->getMessage() . '<br><br>';
					//var_dump($e->getServerResponse()); //more info
				}

			} else {
				
				// alebo pridanie času priamo projektu
				$test_export[$ke] = [
					'export'=> "projektovy",
					'item_data' => [
						'id_project' => $item_data['id_project'],
						'value' => $item_data['time_q'],
						'user_id' => $_SESSION['userLogged']['id'],
						'job_type_id' => $_SESSION['job_type_project'],
						'record_date' => $item_data['date_last'],
						'billable_status' => 1,
					],
				];

				try {
					$time_add = $client->post('projects/'. $item_data['id_project'] .'/time-records', [
						'value' => $item_data['time_q'],
						'user_id' => $_SESSION['userLogged']['id'],
						'job_type_id' => $_SESSION['job_type_project'],
						'record_date' => $item_data['date_last'],
						'billable_status' => 1
					])->getJson();
				} catch(AppException $e) {
					$time_add = $e->getMessage() . '<br><br>';
					//var_dump($e->getServerResponse()); //more info
				}

			}

			if(isset($time_add['single']['updated_on']) && is_int($time_add['single']['updated_on'])) {
				$test_export[$ke]['result'] = "OK";
				$test_export[$ke]['saved'] = $time_add['single']['updated_on'];
				$error_msg = '';
			} else {
				$test_export[$ke]['result'] = "ERR";
				$test_export[$ke]['error'] = $time_add;
				$error_msg = '&nbsp;<span class="badge" data-toggle="tooltip" title="AppException: '.print_r($time_add['message'],1).'" style="cursor:pointer; background-color: #ff0000; font-weight: 900;">!</span>';
				$export_failed++;
				$open_display = 1;
			}

			$exported_table_rows .= '
			<tr>
				<td>'.$item_data['date_last'].'</td>
				<td>'.$item_data['Project'].'</td>
				<td>'.$item_data['Task'].'</td>
				<td>'.$item_data['time_q'].'</td>
				<td>'.$test_export[$ke]['result'].$error_msg.'</td>
			</tr>';


		}

		$exported_table .= '
		<div class="col-md-8 col-md-push-2">
			<div class="table-responsive">
				<table class="table table-striped table-hover">
					<thead>
						<tr>
							<th>Date</th>
							<th>Project</th>
							<th>Task</th>
							<th>time</th>
							<th>výsledok</th>
						</tr>
					</thead>
					<tbody>
					'.$exported_table_rows.'
					</tbody>
				</table>
			</div>
		</div>
		';

    }

	if ($export_failed > 0) {
		$html_content .= '
			<div class="col-md-8 col-md-push-2">
				<div class="alert alert-warning" role="alert">
					<span class="glyphicon glyphicon-warning-sign"></span>&nbsp;&nbsp;<strong>Chyba!</strong> Niektorý záznam bol s chybami. Treba skontrolovať, opraviť a poslať extra.
				</div>
			</div>
		';
	} else {
		$html_content .= '
			<div class="col-md-8 col-md-push-2">
				<div class="alert alert-success" role="alert">
					<strong>Export úspešný!</strong> Záznamy s časmi boli úspešne prenesené do ActiveCollab.
				</div>
			</div>
		';
	}
	
	$html_content .= '
	<div class="col-xs-12"><h4>&nbsp;</h4></div>
	'.$exported_table.'
	<div class="col-xs-12"><h4>&nbsp;</h4></div>
	<div class="col-md-8 col-md-push-2">
		<form action="" method="post" class="text-center">
			<button type="submit" class="btn btn-primary">Hotovo</button>
		</form>
	</div>
	<div class="col-xs-12"><h4>&nbsp;</h4></div>
	';

## Form nahrania CSV súboru (prihlásený užívateľ)
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['userLogged'])) {
	
	$html_content .= '
		<div class="col-md-6 col-md-push-3">
			<p class="text-center text-muted">Súbor CSV s časmi vyexportovanými z <strong class="text-success"><a href="https://desktime.com/app/exports/" target="_blank" title="otvorí sa v novom okne" data-toggle="tooltip">DeskTime</a></strong> <code> > Exports > Group by > Monthly</code><br/>s nastavením <strong>Export period:</strong> <code>Previous month</code> a uložený ako <code>CSV</code> (nie <code>XLSX</code>)<br/>Denné ani týždenné nahrávania časov do ActiveCollab zatiaľ nie sú implementované.</p>
			<form action="" method="post" enctype="multipart/form-data" class="form-signin" style="margin-top: 20px;">
				<label for="file">Vyberte súbor:</label>
				<div class="form-group">
					<div class="input-group">
						<input class="form-control" type="file" name="uploaded_file" id="file">
						<span class="input-group-btn">
							<button type="submit" class="btn btn-primary">
								<span>Nahrať</span>
							</button>
						</span>
					</div>
				</div>
			</form>
			<p class="text-center text-muted"><small>Nahratím súboru CSV sa ešte neodosielajú záznamy do ActiveCollab, len sa zobrazí prehľadová tabuľka so záznamami z CSV. Exportovanie do ActiveCollab sa vykoná až priamym pokynom na stránke s tabuľkou.</small></p>
		</div>
	';

## Úvodná stránka + login (Neprihlásený užívateľ)
} else {

	// 2. KROK - potvrdenie prihlásenia
	if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['email']) && isset($_POST['password']) && isset($_POST['ac_url'])) {

		try {
			$authenticator = new \ActiveCollab\SDK\Authenticator\SelfHosted(
				'ACME Inc', 
				'My Awesome Application', 
				$_POST['email'], 
				$_POST['password'], 
				$_POST['ac_url']
			);

			$token = $authenticator->issueToken();
			$client = new \ActiveCollab\SDK\Client($token);
			$users = $client->get('users')->getJson();

			foreach ($users as $user) {
				if ($user['email'] !== $_POST['email']) {
					continue;
				} else {
					$selected_user = [
						'id'=> $user['id'],
						'email'=> $user['email'],
						'display_name'=> $user['display_name'],
						'company_id'=> $user['company_id'],
						'ac_url'=> $_POST['ac_url'],
						'pass'=> $_POST['password'],
					];
				}
			}
		} catch(Exception $e) {
			$login_failed = $e->getMessage() . '<br><br>';
			//var_dump($e->getServerResponse()); //more info
		}

		if (isset($login_failed) && $login_failed != '') {
			$html_content .= '
				<div class="row">
					<div class="col-md-6 col-md-push-3">
						<div class="alert alert-warning" role="alert">
							<span class="glyphicon glyphicon-warning-sign"></span>&nbsp;&nbsp;<strong>Prihlásenie sa nepodarilo.</strong> Skontrolujte údaje a skúste znovu.
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-md-4 col-md-push-4">
						<div class="text-center">
							<a href="" class="btn btn-lg btn-primary" type="submit">OK</a>
						</div>
					</div>
				</div>
			';
            $open_display = 1;
		} else {
			$html_content .= '
				<div class="row">
					<div class="col-md-4 col-md-push-4">
						<p class="alert alert-success"><span class="glyphicon glyphicon-ok"></span>&nbsp;&nbsp;Spojenie s ActiveCollab prebehlo korektne.</p>
						<div class="form-group col-md-10 col-md-push-1" style="margin-top: 20px;">
							<form action="" method="post">
								<a href="" class="btn btn-default pull-left">Cancel</a>
								<button type="submit" class="btn btn-success pull-right" value="1" name="userLogged">Nahrať DeskTime CSV súbor</button>
							</form>
						</div>
					</div>
				</div>
			';

			$_SESSION['userLogged'] = $selected_user;
		}

	} else {

		session_unset();
		session_destroy();
		$logged_in = 0;

		// 1.KROK - prihlasovací formulár
		$html_content .= '
		<div class="col-md-4 col-md-push-4">
			<form class="form-signin" action="" method="post">
				<div class="form-group">
					<div class="input-group">
						<span class="input-group-addon"><i class="glyphicon glyphicon-link"></i></span>
						<input type="text" id="ac_url" name="ac_url" class="form-control input-lg" placeholder="https://activecollab.mydomain.sk" required autofocus>
					</div>
				</div>
				<div class="form-group">
					<div class="input-group">
						<span class="input-group-addon"><i class="glyphicon glyphicon-user"></i></span>
						<input type="email" id="email" name="email" class="form-control input-lg" placeholder="activecollab e-mail" required autofocus>
					</div>
				</div>
				<div class="form-group">
					<div class="input-group">
						<span class="input-group-addon"><i class="glyphicon glyphicon-lock"></i></span>
						<input type="password" id="password" name="password" class="form-control input-lg" placeholder="activecollab heslo" required>
					</div>
				</div>
				<button class="btn btn-lg btn-primary btn-block btn-signin" type="submit">Potvrdiť</button>
			</form>
		</div>
		';
	}
	
}


		
## Generovanie HTML dev výpisov
    $display_content = '
    
    <div class="col-xs-12"><h4>&nbsp;</h4></div>			
    <div class="col-md-8 col-md-push-2">
        <h5><strong>Error log:</strong></h5>
		<pre style="height:300px; overflow-y:scroll;">
';




//$html_content .= "DeskTime File Content: " . "\n" . print_r($file_content,1) . "\n\n";
//$html_content .= "DeskTime Lines: " . "\n" . print_r($file_rows,1) . "\n\n";
//$html_content .= "DeskTime First Line: " . "\n" . $first_rows . "\n\n";
//$html_content .= "DeskTime Headers: " . "\n" . print_r($headers,1) . "\n\n";
//$html_content .= "DeskTime Data: " . "\n" . print_r($array_data,1) . "\n\n";

(isset($login_failed)) ? ($display_content .= "\n" . "Login failed: " . "\n" . print_r($login_failed,1) . "\n<hr>\n") : null;
(isset($selected_user)) ? ($display_content .= "\n" . "Selected user: " . "\n" . print_r($selected_user,1) . "\n<hr>\n") : null;
//(isset($csv_data_parsed)) ? ($display_content .= "\n" . "CSV dáta: " . "\n" . print_r($csv_data_parsed,1) . "\n<hr>\n") : null;
(isset($acdt_data)) ? ($display_content .= "\n" . "acdt_data: " . "\n" . print_r($acdt_data,1) . "\n<hr>\n") : null;
(isset($af_data)) ? ($display_content .= "\n" . "AfterForm Data: " . "\n" . print_r($af_data,1) . "\n<hr>\n") : null;
(isset($test_export)) ? ($display_content .= "\n" . "Test exportu: " . "\n" . print_r($test_export,1) . "\n<hr>\n") : null;
(isset($project_job_types)) ? ($display_content .= "\n" . "Projec Job Types: " . "\n" . print_r($project_job_types,1) . "\n<hr>\n") : null;
//(isset($user_projects_list)) ? ($display_content .= "\n" . "User projects: " . "\n" . print_r($user_projects_list,1) . "\n<hr>\n") : null;
//(isset($ac_projects)) ? ($display_content .= "\n" . "AC Projekty základ: " . "\n" . print_r($ac_projects,1) . "\n<hr>\n") : null;
//(isset($ac_tasks)) ? ($display_content .= "\n" . "AC Tasky základ: " . "\n" . print_r($ac_tasks,1) . "\n<hr>\n") : null;



$display_content .= '
        </pre>
    </div>
';

if ($open_display == 1) {
    $html_content .= $display_content;
}





## koniec PHP
?>
<!doctype html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<meta name="description" content="Ukladanie časov exportovaných z DeskTime priamo cez API do ActiveCollab">
	<title>DeskTime2ActiveCollab</title>

	<link rel="icon" type="image/png" href="img/favicon-96x96.png" sizes="96x96" />
	<link rel="icon" type="image/svg+xml" href="img/favicon.svg" />
	<link rel="shortcut icon" href="img/favicon.ico" />
	<link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon.png" />
	<link rel="manifest" href="img/site.webmanifest" />

	<script src="vendor/jquery/v3.7.1/jquery.min.js"></script>
	<script src="vendor/bootstrap.3.4/js/bootstrap.min.js"></script>

	<link href="vendor/bootstrap.3.4/css/bootstrap.min.css" rel="stylesheet">

	<style>
		/* sticky footer */
		html {
			position: relative;
			min-height: 100%;
		}
		body {
			margin-bottom: 60px;
		}
		footer {
			position: absolute;
			bottom: 0;
			width: 100%;
		}
	</style>
</head>

<body class=" bg-light">

	<div class="container">
		<div class="row">
			<div class="col-md-8 col-md-push-2">
			<?php if(!isset($logged_in) || $logged_in == 0) { ?>
				<div class="col-xs-12"><h4>&nbsp;</h4></div>
				<div class="text-center">
					<img id="profile-img" class="profile-img-card" src="img/favicon-96x96.png" />
				</div>
			<?php } ?>
				<h2 class="text-center">DeskTime2ActiveCollab</h2>
				<h4 class="text-center">Nahrávanie časov vyexportovaných z DeskTime, do záznamov v ActiveCollab</h4>
			<?php if(isset($logged_in) && $logged_in == 1) { ?>
				<p class="text-center">Aktuálne prihlásenie:</p>
				<h4 class="text-center text-info"><span class="glyphicon glyphicon-user text-muted"></span>&nbsp;&nbsp;<strong><?php echo $_SESSION['userLogged']['display_name'] ?></strong></h4>
			<?php } ?>
			</div>
		</div>
		<div class="row">
			<div class="col-xs-12"><h4>&nbsp;</h4></div>
			<?php echo $html_content; ?>
		</div>
	</div>
	<!--
	<?php if ($open_display == 1) { ?>
	<pre>SESSIONS:<?php echo "\n"; print_r($_SESSION)?></pre>
	<?php } ?>
	-->
	<footer class="text-center" style="margin-top: 50px; padding: 20px; background-color: #f8f9fa;">
		<p class="text-muted">Vyrobené pre radosť z kódovania &bull; <?php echo date('Y');?></p>
	</footer>

	<script>
		$(document).ready(function(){
			$('[data-toggle="tooltip"]').tooltip();   
		});
	</script>
</body>

</html>
