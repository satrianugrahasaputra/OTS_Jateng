<?php

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

if (!function_exists('ots_allowed_data_tables')) {
	function ots_allowed_data_tables() {
		return [
			'ots_inflasi' => true,
			't_inflasi' => true,
			't_ntp' => true,
			'ots_ekspor' => true,
			't_ekspor' => true,
			'ots_impor' => true,
			't_impor' => true,
			't_neraca' => true,
			't_ipm_prov' => true,
			't_ipm_kab' => true,
			't_miskin_prov' => true,
			't_miskin_kab' => true,
			't_giniratio' => true,
			't_naker_prov' => true,
			't_naker_kab' => true,
			't_pdrb_prov' => true,
			't_pdrb_kab' => true,
			't_pdrb_tahunan' => true,
			'ots_ind_rpjpn' => true,
			'ots_skd' => true,
			'ots_skd_tahunan' => true,
			'tabel_webapi' => true,
			'test_sync' => true
		];
	}

	function ots_assert_allowed_table($table) {
		$allowedTables = ots_allowed_data_tables();
		if (!is_string($table) || !isset($allowedTables[$table])) {
			throw new InvalidArgumentException("Tabel '$table' tidak diizinkan.");
		}
		return $table;
	}

	function ots_quote_identifier($identifier) {
		if (!is_string($identifier) || !preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
			throw new InvalidArgumentException("Identifier '$identifier' tidak valid.");
		}
		return '`' . $identifier . '`';
	}

	function ots_normalize_year($year) {
		$year = trim((string) $year);
		if (!preg_match('/^\d{4}$/', $year)) {
			return date('Y');
		}
		return $year;
	}

	function ots_describe_table(PDO $db, $table) {
		static $cache = [];
		$table = ots_assert_allowed_table($table);
		if (!isset($cache[$table])) {
			$stmt = $db->query("DESCRIBE " . ots_quote_identifier($table));
			$cache[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
		}
		return $cache[$table];
	}

	function ots_column_map(PDO $db, $table) {
		$columns = [];
		foreach (ots_describe_table($db, $table) as $column) {
			$columns[$column['Field']] = $column;
		}
		return $columns;
	}

	function ots_default_value_for_column(array $column) {
		$type = strtolower($column['Type']);
		if (array_key_exists('Default', $column) && $column['Default'] !== null) {
			return $column['Default'];
		}
		if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false || strpos($type, 'numeric') !== false) {
			return 0;
		}
		if (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
			return null;
		}
		return '-';
	}

	function ots_default_row(PDO $db, $table) {
		$row = [];
		foreach (ots_describe_table($db, $table) as $column) {
			$field = $column['Field'];
			if ($field === 'id') {
				continue;
			}
			$row[$field] = ots_default_value_for_column($column);
		}
		return $row;
	}

	function ots_has_meaningful_data(array $row, array $fields) {
		foreach ($fields as $field) {
			if (!array_key_exists($field, $row)) {
				continue;
			}
			$value = $row[$field];
			if ($value === null) {
				continue;
			}
			if (is_string($value) && trim($value) === '') {
				continue;
			}
			if ($value === '-') {
				continue;
			}
			return true;
		}
		return false;
	}

	function ots_empty_sync_stats() {
		return [
			'inserted' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors' => []
		];
	}

	function ots_add_sync_stat(array &$stats, array $result) {
		$action = isset($result['action']) ? $result['action'] : 'error';
		if ($action === 'inserted' || $action === 'updated' || $action === 'skipped') {
			$stats[$action]++;
			return;
		}
		$stats['errors'][] = isset($result['message']) ? $result['message'] : 'Unknown sync error';
	}

	function ots_success_count(array $stats) {
		return (int) $stats['inserted'] + (int) $stats['updated'];
	}

	function ots_sync_status(array $stats) {
		if (empty($stats['errors'])) {
			return 'success';
		}
		return ots_success_count($stats) > 0 ? 'partial' : 'error';
	}

	function ots_delete_rows_by_year(PDO $db, $table, $year) {
		try {
			$table = ots_assert_allowed_table($table);
			$stmt = $db->prepare("DELETE FROM " . ots_quote_identifier($table) . " WHERE " . ots_quote_identifier('tahun') . " = :tahun");
			$stmt->execute([':tahun' => ots_normalize_year($year)]);
			return ['action' => 'deleted'];
		} catch (Exception $e) {
			error_log("Delete by year failed for $table: " . $e->getMessage());
			return ['action' => 'error', 'message' => $e->getMessage()];
		}
	}

	function ots_sync_upsert_row(PDO $db, $table, array $row, array $keys, array $meaningfulFields = []) {
		try {
			$table = ots_assert_allowed_table($table);

			if (!empty($meaningfulFields) && !ots_has_meaningful_data($row, $meaningfulFields)) {
				return ['action' => 'skipped', 'message' => "Tidak ada meaningful data untuk $table."];
			}

			$columns = ots_column_map($db, $table);
			foreach ($keys as $key) {
				if (!array_key_exists($key, $row) || !isset($columns[$key])) {
					return ['action' => 'error', 'message' => "Key '$key' tidak lengkap untuk tabel $table."];
				}
			}

			$where = [];
			$whereParams = [];
			$idx = 0;
			foreach ($keys as $key) {
				$param = ':k' . $idx++;
				$where[] = ots_quote_identifier($key) . " = $param";
				$whereParams[$param] = $row[$key];
			}

			$selectId = isset($columns['id']) ? ots_quote_identifier('id') : '1 AS found';
			$stmt = $db->prepare("SELECT $selectId FROM " . ots_quote_identifier($table) . " WHERE " . implode(' AND ', $where) . " LIMIT 1");
			$stmt->execute($whereParams);
			$existing = $stmt->fetch(PDO::FETCH_ASSOC);

			if ($existing) {
				$set = [];
				$params = [];
				$idx = 0;
				foreach ($row as $col => $val) {
					if ($col === 'id' || in_array($col, $keys, true) || !isset($columns[$col])) {
						continue;
					}
					$param = ':u' . $idx++;
					$set[] = ots_quote_identifier($col) . " = $param";
					$params[$param] = $val;
				}

				if (empty($set)) {
					return ['action' => 'skipped', 'message' => "Tidak ada kolom update untuk $table."];
				}

				if (isset($columns['id']) && isset($existing['id'])) {
					$params[':id'] = $existing['id'];
					$sql = "UPDATE " . ots_quote_identifier($table) . " SET " . implode(', ', $set) . " WHERE " . ots_quote_identifier('id') . " = :id";
				} else {
					$params = array_merge($params, $whereParams);
					$sql = "UPDATE " . ots_quote_identifier($table) . " SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where);
				}

				$db->prepare($sql)->execute($params);
				return ['action' => 'updated'];
			}

			$insertRow = ots_default_row($db, $table);
			foreach ($row as $col => $val) {
				if ($col === 'id' || !isset($columns[$col])) {
					continue;
				}
				$insertRow[$col] = $val;
			}

			$cols = [];
			$params = [];
			$placeholders = [];
			$idx = 0;
			foreach ($insertRow as $col => $val) {
				if (!isset($columns[$col])) {
					continue;
				}
				$param = ':i' . $idx++;
				$cols[] = ots_quote_identifier($col);
				$placeholders[] = $param;
				$params[$param] = $val;
			}

			$sql = "INSERT INTO " . ots_quote_identifier($table) . " (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
			$db->prepare($sql)->execute($params);
			return ['action' => 'inserted'];
		} catch (Exception $e) {
			error_log("Sync upsert failed for $table: " . $e->getMessage());
			return ['action' => 'error', 'message' => $e->getMessage()];
		}
	}
}

return function (App $app) {
	$container = $app->getContainer();
	$syncSecret = ots_env('SYNC_SECRET', 'OTS_SYNC_SECRET_2026');
	$bpsApiKey = ots_env('BPS_API_KEY', '98aed4f1c15772355ce733df19243b4e');

	$app->get("/", function (Request $request, Response $response) {
		return $response->withJson(["status" => "OK", "API by" => "Mufti Ramadhperan"], 200);
	});

	// ============================================
	// ADMIN AUTHENTICATION
	// ============================================
	
	// Login View
	$app->get('/admin/login', function ($request, $response) {
		return $this->renderer->render($response, 'admin/login.php');
	});

	// Login Action
	$app->post('/admin/login', function ($request, $response) {
		$db = $this->db;
		$data = $request->getParsedBody();
		$username = isset($data['username']) ? $data['username'] : '';
		$password = isset($data['password']) ? $data['password'] : '';

		$stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
		$stmt->execute([$username]);
		$user = $stmt->fetch();

		if ($user && password_verify($password, $user['password'])) {
			$_SESSION['user_id'] = $user['id'];
			$_SESSION['username'] = $user['username'];
			return $response->withJson(['status' => 'success']);
		}

		return $response->withJson(['status' => 'error', 'message' => 'Username atau password salah.'], 401);
	});

	// Logout
	$app->get('/admin/logout', function ($request, $response) {
		session_destroy();
		return $response->withRedirect('/admin/login');
	});

	// ============================================
	// PROTECTED ADMIN & SYNC API ROUTES
	// ============================================
	$app->group('', function () use ($app) {

		// Dashboard View
		$app->get('/admin/dashboard', function ($request, $response) {
			return $this->renderer->render($response, 'admin/dashboard.php');
		});

		// PDRB Sync View
		$app->get('/admin/pdrb', function ($request, $response) {
			return $this->renderer->render($response, 'admin/pdrb.php');
		});

		// User Management View
		$app->get('/admin/users', function ($request, $response) {
			$db = $this->db;
			$stmt = $db->query("SELECT id, username, created_at FROM users ORDER BY id DESC");
			$users = $stmt->fetchAll();
			return $this->renderer->render($response, 'admin/users.php', ['users' => $users]);
		});

		// API: Add User
		$app->post('/api/admin/users', function ($request, $response) {
			$db = $this->db;
			$data = $request->getParsedBody();
			$username = isset($data['username']) ? $data['username'] : '';
			$password = isset($data['password']) ? $data['password'] : '';

			if (empty($username) || empty($password)) {
				return $response->withJson(['status' => 'error', 'message' => 'Username dan password wajib diisi.'], 400);
			}

			$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
			try {
				$stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
				$stmt->execute([$username, $hashedPassword]);
				return $response->withJson(['status' => 'success', 'message' => 'User berhasil ditambahkan.']);
			} catch (Exception $e) {
				return $response->withJson(['status' => 'error', 'message' => 'Gagal menambah user: ' . $e->getMessage()], 500);
			}
		});

		// API: Delete User
		$app->delete('/api/admin/users/{id}', function ($request, $response, $args) {
			$db = $this->db;
			$id = $args['id'];

			if ($id == $_SESSION['user_id']) {
				return $response->withJson(['status' => 'error', 'message' => 'Anda tidak bisa menghapus akun sendiri.'], 400);
			}

			try {
				$stmt = $db->prepare("DELETE FROM users WHERE id = ?");
				$stmt->execute([$id]);
				return $response->withJson(['status' => 'success', 'message' => 'User berhasil dihapus.']);
			} catch (Exception $e) {
				return $response->withJson(['status' => 'error', 'message' => 'Gagal menghapus user.'], 500);
			}
		});

		// Protected Sync API Routes (Aliased for /api prefix as well if needed)
		$app->get('/api/preview-sync', function ($request, $response) use ($app) {
			$queryParams = http_build_query($request->getQueryParams());
			return $app->subRequest('GET', '/preview-sync', $queryParams);
		});

		$app->post('/api/save-sync', function ($request, $response) use ($app) {
			return $app->subRequest('POST', '/save-sync', '', [], [], (string)$request->getBody());
		});

		// API: Manual Sync Trigger (menggantikan Task Scheduler / sync_cron.bat)
		$app->get('/api/admin/run-sync', function ($request, $response) use ($app, $syncSecret) {
			$year = ots_normalize_year($request->getQueryParam('year', date('Y')));

			// Internally call the /run-sync-all endpoint
			$env = \Slim\Http\Environment::mock([
				'REQUEST_METHOD' => 'GET',
				'REQUEST_URI' => '/run-sync-all',
				'QUERY_STRING' => 'year=' . $year . '&token=' . rawurlencode($syncSecret)
			]);
			$subReq = \Slim\Http\Request::createFromEnvironment($env);
			$subResp = new \Slim\Http\Response();
			$subResp = $app->process($subReq, $subResp);

			$body = json_decode((string)$subResp->getBody(), true);

			return $response->withJson([
				'status' => isset($body['status']) ? $body['status'] : 'error',
				'triggered_at' => date('Y-m-d H:i:s'),
				'year' => $year,
				'summary' => isset($body['summary']) ? $body['summary'] : null,
				'details' => isset($body['details']) ? $body['details'] : null
			], $subResp->getStatusCode());
		});

	})->add($app->getContainer()['authMiddleware']);

	$app->get("/indikator/{indikator}/{tahun}", function (Request $request, Response $response, $args) {
		$indikator = $args["indikator"];
		$tahun = $args["tahun"];

		if ($indikator == "inflasi") {

		} else {

		}

		return $response->withJson(["status" => $indikator, "tahun" => $tahun], 200);
	});

	$app->get("/tahun/{indikator}", function (Request $request, Response $response, $args) {
		$indikator = $args["indikator"];
		if (strpos($indikator, "inflasi") !== false) {
			$sql = "SELECT DISTINCT tahun FROM t_inflasi ORDER BY tahun DESC";
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$result = $stmt->fetchAll();
			$sql = "SELECT DISTINCT tahun FROM ots_inflasi ORDER BY tahun DESC";
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$result1 = $stmt->fetchAll();
			$arr = array_merge($result1, $result);
		} else if (strpos($indikator, "ekspor") !== false) {
			$sql = "SELECT DISTINCT tahun FROM t_ekspor ORDER BY tahun DESC";
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$result = $stmt->fetchAll();
			$sql = "SELECT DISTINCT tahun FROM ots_ekspor ORDER BY tahun DESC";
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$result1 = $stmt->fetchAll();
			$arr = array_merge($result1, $result);
		} else if (strpos($indikator, "impor") !== false) {
			$sql = "SELECT DISTINCT tahun FROM t_impor ORDER BY tahun DESC";
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$result = $stmt->fetchAll();
			$sql = "SELECT DISTINCT tahun FROM ots_impor ORDER BY tahun DESC";
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$result1 = $stmt->fetchAll();
			$arr = array_merge($result1, $result);
		} else {
			try {
				$indikator = ots_assert_allowed_table($indikator);
			} catch (Exception $e) {
				return $response->withJson(["status" => "error", "message" => $e->getMessage()], 400);
			}
			$sql = "SELECT DISTINCT tahun FROM " . ots_quote_identifier($indikator) . " ORDER BY tahun DESC";
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$result = $stmt->fetchAll();
			$arr = $result;
		}
		return $response->withJson(["status" => "success", "data" => $arr], 200);
	});
	/*
		$app->get("/dashboard/{lang}", function (Request $request, Response $response, $args){
			$lang = $args["lang"];
			if (($lang == "in" || $lang == "id")){
				$sql = "SELECT 'inflasi' AS 'id', 'Inflasi' AS 'indikator', bulan AS bulan, tahun, month AS nilai, tanda, poin, sebelumnya, 'persen' AS 'satuan', '' AS 'delta' FROM (SELECT ots_inflasi.id, m_bulan.bulan, ots_inflasi.tahun, ots_inflasi.month, ots_inflasi.tanda, ots_inflasi.poin, ots_inflasi.sebelumnya FROM ots_inflasi JOIN m_bulan ON ots_inflasi.bulan = m_bulan.id ORDER BY id DESC LIMIT 1) as a UNION ALL 
						SELECT 'ntp', 'Nilai Tukar Petani / NTP', des_bulan, tahun, ntp, tanda, poin, sebelumnya, '', '%' FROM (SELECT id, des_bulan, tahun, ntp, tanda, poin, sebelumnya FROM t_ntp ORDER BY id DESC LIMIT 1) as b UNION ALL 
						SELECT 'ekspor', 'Ekspor', des_bulan, tahun, nilai_ekspor, tanda, poin, sebelumnya, 'Juta US$', '%' FROM (SELECT id, des_bulan, tahun, nilai_ekspor, tanda, poin, sebelumnya FROM ots_ekspor ORDER BY id DESC LIMIT 1) as c UNION ALL
						SELECT 'impor', 'Impor', des_bulan, tahun, nilai_impor, tanda, poin, sebelumnya, 'Juta US$', '%' FROM (SELECT id, des_bulan, tahun, nilai_impor, tanda, poin, sebelumnya FROM ots_impor ORDER BY id DESC LIMIT 1) as d UNION ALL
						SELECT 'neraca', 'Neraca Perdagangan', des_bulan, tahun, nilai, tanda, '', '', 'Juta US$', '' FROM (SELECT id, des_bulan, tahun, nilai, tanda, nilai_kum, sebelumnya FROM t_neraca ORDER BY id DESC LIMIT 1) as e UNION ALL
						SELECT 'pdrb_prov', 'Pertumbuhan Ekonomi', des_triwulan, tahun,  yy, tanda, poin, sebelumnya, 'persen', '' FROM (SELECT id, des_triwulan, tahun,  yy, tanda, poin, sebelumnya FROM t_pdrb_prov ORDER BY id DESC LIMIT 1) as f UNION ALL
						SELECT 'miskin', 'Kemiskinan', des_bulan, tahun, p0_kotadesa, tanda, poin, sebelumnya, 'persen', 'poin' FROM (SELECT id, des_bulan, tahun, p0_kotadesa, tanda, poin, sebelumnya FROM t_miskin_prov ORDER BY id DESC LIMIT 1) as g UNION ALL
						SELECT 'gini_ratio', 'Gini Ratio', des_bulan, tahun, gr_desakota, tanda, poin, sebelumnya, '', 'poin' FROM (SELECT id, des_bulan, tahun, gr_desakota, tanda, poin, sebelumnya FROM t_giniratio ORDER BY id DESC LIMIT 1) as h UNION ALL
						SELECT 'naker', 'Tingkat Pengangguran Terbuka / TPT', des_bulan, tahun, tpt, tanda, poin, sebelumnya, 'persen', 'poin' FROM (SELECT id, des_bulan, tahun, tpt, tanda, poin, sebelumnya FROM t_naker_prov ORDER BY id DESC LIMIT 1) as i UNION ALL
						SELECT 'ipm', 'Indeks Pembangunan Manusia / IPM', 'tahun' , tahun, ipm, tanda, ipm_growth, sebelumnya, '', 'poin' FROM (SELECT id, tahun, ipm, tanda, ipm_growth, sebelumnya FROM t_ipm_prov ORDER BY id DESC LIMIT 1) as j";
			}else if($lang == "en"){
				$sql = "SELECT 'inflasi' AS 'id', 'Inflation' AS 'indikator', month_ AS bulan, tahun, month AS nilai, tanda, poin, sebelumnya, 'persen' AS 'satuan', '' AS 'delta' FROM (SELECT ots_inflasi.id, m_bulan.month AS month_, ots_inflasi.tahun, ots_inflasi.month, ots_inflasi.tanda, ots_inflasi.poin, ots_inflasi.sebelumnya FROM ots_inflasi JOIN m_bulan ON ots_inflasi.bulan = m_bulan.id ORDER BY id DESC LIMIT 1) as a UNION ALL 
						SELECT 'ntp', 'Farmers Exchange Rate / NTP', des_bulan, tahun, ntp, tanda, poin, sebelumnya, '', '%' FROM (SELECT id, des_bulan, tahun, ntp, tanda, poin, sebelumnya FROM t_ntp ORDER BY id DESC LIMIT 1) as b UNION ALL 
						SELECT 'ekspor', 'Export', des_bulan, tahun, nilai_ekspor, tanda, poin, sebelumnya, 'Juta US$', '%' FROM (SELECT id, des_bulan, tahun, nilai_ekspor, tanda, poin, sebelumnya FROM ots_ekspor ORDER BY id DESC LIMIT 1) as c UNION ALL
						SELECT 'impor', 'Import', des_bulan, tahun, nilai_impor, tanda, poin, sebelumnya, 'Juta US$', '%' FROM (SELECT id, des_bulan, tahun, nilai_impor, tanda, poin, sebelumnya FROM ots_impor ORDER BY id DESC LIMIT 1) as d UNION ALL
						SELECT 'neraca', 'Balance of Trade', des_bulan, tahun, nilai, tanda, '', '', 'Juta US$', '' FROM (SELECT id, des_bulan, tahun, nilai, tanda, nilai_kum, sebelumnya FROM t_neraca ORDER BY id DESC LIMIT 1) as e UNION ALL
						SELECT 'pdrb_prov', 'Economic Growth', des_triwulan, tahun,  yy, tanda, poin, sebelumnya, 'persen', '' FROM (SELECT id, des_triwulan, tahun,  yy, tanda, poin, sebelumnya FROM t_pdrb_prov ORDER BY id DESC LIMIT 1) as f UNION ALL
						SELECT 'miskin', 'Poverty', des_bulan, tahun, p0_kotadesa, tanda, poin, sebelumnya, 'persen', 'poin' FROM (SELECT id, des_bulan, tahun, p0_kotadesa, tanda, poin, sebelumnya FROM t_miskin_prov ORDER BY id DESC LIMIT 1) as g UNION ALL
						SELECT 'gini_ratio', 'Gini Ratio', des_bulan, tahun, gr_desakota, tanda, poin, sebelumnya, '', 'poin' FROM (SELECT id, des_bulan, tahun, gr_desakota, tanda, poin, sebelumnya FROM t_giniratio ORDER BY id DESC LIMIT 1) as h UNION ALL
						SELECT 'naker', 'Open Unemployment Rate / TPT', des_bulan, tahun, tpt, tanda, poin, sebelumnya, 'persen', 'poin' FROM (SELECT id, des_bulan, tahun, tpt, tanda, poin, sebelumnya FROM t_naker_prov ORDER BY id DESC LIMIT 1) as i UNION ALL
						SELECT 'ipm', 'Human Development Index / IPM', 'tahun' , tahun, ipm, tanda, ipm_growth, sebelumnya, '', 'poin' FROM (SELECT id, tahun, ipm, tanda, ipm_growth, sebelumnya FROM t_ipm_prov ORDER BY id DESC LIMIT 1) as j";
			}
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$result = $stmt->fetchAll();

			$arrImage = array("image" => "https://webapps.bps.go.id/jateng/api_image/sp2020.gif", "link" => "https://sensus.bps.go.id");

			return $response->withJson(["status" => "success", "image" => $arrImage, "data" => $result], 200);
		});
	*/
	$app->get("/dashboard/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$sql = "(SELECT 'inflasi' AS 'id', 'Inflasi' AS 'indikator', m_bulan.bulan, ots_inflasi.tahun, ots_inflasi.month AS nilai, ots_inflasi.tanda, ots_inflasi.poin, ots_inflasi.sebelumnya, 'persen' AS 'satuan', '' AS 'delta', '" . $lang . "' AS lang FROM ots_inflasi JOIN m_bulan ON ots_inflasi.bulan = m_bulan.id ORDER BY ots_inflasi.id DESC LIMIT 1) UNION ALL 
    				(SELECT 'ntp', 'Nilai Tukar Petani / NTP', m_bulan.bulan, t_ntp.tahun, t_ntp.ntp, t_ntp.tanda, t_ntp.poin, t_ntp.sebelumnya, '', '%', '" . $lang . "' AS lang FROM t_ntp JOIN m_bulan ON t_ntp.bulan = m_bulan.id ORDER BY t_ntp.id DESC LIMIT 1) UNION ALL 
    				(SELECT 'ekspor', 'Ekspor', m_bulan.bulan, ots_ekspor.tahun, ots_ekspor.nilai_ekspor, ots_ekspor.tanda, ots_ekspor.poin, ots_ekspor.sebelumnya, 'Juta US$', '%', '" . $lang . "' AS lang FROM ots_ekspor JOIN m_bulan ON ots_ekspor.bulan = m_bulan.id ORDER BY ots_ekspor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'impor', 'Impor', m_bulan.bulan, ots_impor.tahun, ots_impor.nilai_impor, ots_impor.tanda, ots_impor.poin, ots_impor.sebelumnya, 'Juta US$', '%', '" . $lang . "' AS lang FROM ots_impor JOIN m_bulan ON ots_impor.bulan = m_bulan.id ORDER BY ots_impor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'neraca', 'Neraca Perdagangan', m_bulan.bulan, t_neraca.tahun, t_neraca.nilai, t_neraca.tanda, '', '', 'Juta US$', '', '" . $lang . "' AS lang FROM t_neraca JOIN m_bulan ON t_neraca.bulan = m_bulan.id ORDER BY t_neraca.id DESC LIMIT 1) UNION ALL
    				(SELECT 'pdrb_prov', 'Pertumbuhan Ekonomi', des_triwulan, tahun, yy, tanda, poin, sebelumnya, 'persen', '', '" . $lang . "' AS lang FROM t_pdrb_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'miskin', 'Kemiskinan', m_bulan.bulan, t_miskin_prov.tahun, t_miskin_prov.p0_kotadesa, t_miskin_prov.tanda, t_miskin_prov.poin, t_miskin_prov.sebelumnya, 'persen', 'poin', '" . $lang . "' AS lang FROM t_miskin_prov JOIN m_bulan ON t_miskin_prov.bulan = m_bulan.id ORDER BY t_miskin_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'gini_ratio', 'Gini Ratio', m_bulan.bulan, t_giniratio.tahun, t_giniratio.gr_desakota, t_giniratio.tanda, t_giniratio.poin, t_giniratio.sebelumnya, '', 'poin', '" . $lang . "' AS lang FROM t_giniratio JOIN m_bulan ON t_giniratio.bulan = m_bulan.id ORDER BY t_giniratio.id DESC LIMIT 1) UNION ALL
    				(SELECT 'naker', 'Tingkat Pengangguran Terbuka / TPT', m_bulan.bulan, t_naker_prov.tahun, t_naker_prov.tpt, t_naker_prov.tanda, t_naker_prov.poin, t_naker_prov.sebelumnya, 'persen', 'poin', '" . $lang . "' AS lang FROM t_naker_prov JOIN m_bulan ON t_naker_prov.bulan = m_bulan.id ORDER BY t_naker_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipm', 'Indeks Pembangunan Manusia / IPM', 'tahun' , tahun, ipm, tanda, ipm_growth, sebelumnya, '', 'poin', '" . $lang . "' AS lang FROM t_ipm_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'skm', 'Indeks Persepsi Kualitas Pelayanan', trw , tahun, ipkp, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipak', 'Indeks Persepsi Anti Korupsi', trw , tahun, ipak, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1)";
		} else if ($lang == "en") {
			//			(SELECT 'rpjpn' AS id, '45 Indikator Utama Pembangunan RPJPN' AS indikator, '' AS bulan, '' AS tahun, '0.00' AS nilai, '' AS tanda, '0.00' AS poin, '' AS sebelumnya, '' AS satuan, '' AS delta, 'en' AS lang FROM ots_ind_rpjpn ORDER BY id_indikator DESC LIMIT 1) UNION ALL
//					(SELECT 'inflasi', 'Inflation', m_bulan.bulan, ots_inflasi.tahun, ots_inflasi.month, ots_inflasi.sign, ots_inflasi.poin, ots_inflasi.month_before, 'percent', '', '" . $lang ."' FROM ots_inflasi JOIN m_bulan ON ots_inflasi.bulan = m_bulan.id ORDER BY ots_inflasi.id DESC LIMIT 1) UNION ALL
// 					(SELECT 'inflasi' AS id, 'Inflation' AS indikator, m_bulan.bulan AS bulan, ots_inflasi.tahun AS tahun, ots_inflasi.month AS nilai, ots_inflasi.sign AS tanda, ots_inflasi.poin AS poin, ots_inflasi.month_before AS sebelumnya, 'percent' AS satuan, '' AS delta, '" . $lang ."' AS lang FROM ots_inflasi JOIN m_bulan ON ots_inflasi.bulan = m_bulan.id ORDER BY ots_inflasi.id DESC LIMIT 1) UNION ALL
			$sql = "
					(SELECT 'inflasi' AS id, 'Inflation' AS indikator, m_bulan.bulan AS bulan, ots_inflasi.tahun AS tahun, ots_inflasi.month AS nilai, ots_inflasi.sign AS tanda, ots_inflasi.poin AS poin, ots_inflasi.month_before AS sebelumnya, 'percent' AS satuan, '' AS delta, '" . $lang . "' AS lang FROM ots_inflasi JOIN m_bulan ON ots_inflasi.bulan = m_bulan.id ORDER BY ots_inflasi.id DESC LIMIT 1) UNION ALL
    				(SELECT 'ntp', 'Farmers Exchange Rate / NTP', m_bulan.bulan, t_ntp.tahun, t_ntp.ntp, t_ntp.sign, t_ntp.poin, t_ntp.month_before, '', '%', '" . $lang . "' AS lang FROM t_ntp JOIN m_bulan ON t_ntp.bulan = m_bulan.id ORDER BY t_ntp.id DESC LIMIT 1) UNION ALL 
    				(SELECT 'ekspor', 'Export', m_bulan.bulan, ots_ekspor.tahun, ots_ekspor.nilai_ekspor, ots_ekspor.sign, ots_ekspor.poin, ots_ekspor.month_before, 'Million US$', '%', '" . $lang . "' AS lang FROM ots_ekspor JOIN m_bulan ON ots_ekspor.bulan = m_bulan.id ORDER BY ots_ekspor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'impor', 'Import', m_bulan.bulan, ots_impor.tahun, ots_impor.nilai_impor, ots_impor.sign, ots_impor.poin, ots_impor.month_before, 'Million US$', '%', '" . $lang . "' AS lang FROM ots_impor JOIN m_bulan ON ots_impor.bulan = m_bulan.id ORDER BY ots_impor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'neraca', 'Balance of Trade', m_bulan.bulan, t_neraca.tahun, t_neraca.nilai, t_neraca.sign, '', '', 'Million US$', '', '" . $lang . "' AS lang FROM t_neraca JOIN m_bulan ON t_neraca.bulan = m_bulan.id ORDER BY t_neraca.id DESC LIMIT 1) UNION ALL
    				(SELECT 'pdrb_prov', 'Economic Growth', des_triwulan, tahun, yy, sign, poin, q_before, 'percent', '', '" . $lang . "' AS lang FROM t_pdrb_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'miskin', 'Poverty', m_bulan.bulan, t_miskin_prov.tahun, t_miskin_prov.p0_kotadesa, t_miskin_prov.sign, t_miskin_prov.poin, t_miskin_prov.month_before, 'percent', 'poin', '" . $lang . "' AS lang FROM t_miskin_prov JOIN m_bulan ON t_miskin_prov.bulan = m_bulan.id ORDER BY t_miskin_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'gini_ratio', 'Gini Ratio', m_bulan.bulan, t_giniratio.tahun, t_giniratio.gr_desakota, t_giniratio.sign, t_giniratio.poin, t_giniratio.month_before, '', 'poin', '" . $lang . "' AS lang FROM t_giniratio JOIN m_bulan ON t_giniratio.bulan = m_bulan.id ORDER BY t_giniratio.id DESC LIMIT 1) UNION ALL
    				(SELECT 'naker', 'Open Unemployment Rate / TPT', m_bulan.bulan, t_naker_prov.tahun, t_naker_prov.tpt, t_naker_prov.sign, t_naker_prov.poin, t_naker_prov.month_before, 'percent', 'poin', '" . $lang . "' AS lang FROM t_naker_prov JOIN m_bulan ON t_naker_prov.bulan = m_bulan.id ORDER BY t_naker_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipm', 'Human Development Index / IPM', 'tahun' , tahun, ipm, sign, ipm_growth, sebelumnya, '', 'poin', '" . $lang . "' AS lang FROM t_ipm_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'skm', 'Service Quality Perception Index', trw , tahun, ipkp, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipak', 'Anti Corruption Perception Index', trw , tahun, ipak, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1)";
		}
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		$arrImage = array("image" => "https://10.bpsjateng.my.id/api_image/banner.png", "link" => "https://jateng.bps.go.id");
		$arrMaklumat = array("image" => "https://10.bpsjateng.my.id/api_image/maklumat.png", "link" => "https://ppid.bps.go.id/app/konten/3300/Standar-Layanan-Informasi-Publik.html#pills-1");

		return $response->withJson(["status" => "success", "image" => $arrImage, "maklumat" => $arrMaklumat, "data" => $result], 200);
	});

	$app->get("/dashboard2/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$sql = "(SELECT 'rpjpn' AS id, 'Indikator Utama\nPembangunan Daerah\nProvinsi Jawa Tengah' AS indikator, '' AS bulan, '' AS tahun, '0.00' AS nilai, '' AS tanda, '0.00' AS poin, '' AS sebelumnya, '' AS satuan, '' AS delta, 'en' AS lang FROM ots_ind_rpjpn ORDER BY id_indikator DESC LIMIT 1) UNION ALL
					(SELECT 'inflasi', 'Inflasi', m_bulan.bulan, ots_inflasi.tahun, ots_inflasi.month, ots_inflasi.tanda, ots_inflasi.poin, ots_inflasi.sebelumnya, 'persen', '', '" . $lang . "' FROM ots_inflasi JOIN m_bulan ON ots_inflasi.bulan = m_bulan.id ORDER BY ots_inflasi.id DESC LIMIT 1) UNION ALL 
    				(SELECT 'ntp', 'Nilai Tukar Petani / NTP', m_bulan.bulan, t_ntp.tahun, t_ntp.ntp, t_ntp.tanda, t_ntp.poin, t_ntp.sebelumnya, '', '%', '" . $lang . "' AS lang FROM t_ntp JOIN m_bulan ON t_ntp.bulan = m_bulan.id ORDER BY t_ntp.id DESC LIMIT 1) UNION ALL 
    				(SELECT 'ekspor', 'Ekspor', m_bulan.bulan, ots_ekspor.tahun, ots_ekspor.nilai_ekspor, ots_ekspor.tanda, ots_ekspor.poin, ots_ekspor.sebelumnya, 'Juta US$', '%', '" . $lang . "' AS lang FROM ots_ekspor JOIN m_bulan ON ots_ekspor.bulan = m_bulan.id ORDER BY ots_ekspor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'impor', 'Impor', m_bulan.bulan, ots_impor.tahun, ots_impor.nilai_impor, ots_impor.tanda, ots_impor.poin, ots_impor.sebelumnya, 'Juta US$', '%', '" . $lang . "' AS lang FROM ots_impor JOIN m_bulan ON ots_impor.bulan = m_bulan.id ORDER BY ots_impor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'neraca', 'Neraca Perdagangan', m_bulan.bulan, t_neraca.tahun, t_neraca.nilai, t_neraca.tanda, '', '', 'Juta US$', '', '" . $lang . "' AS lang FROM t_neraca JOIN m_bulan ON t_neraca.bulan = m_bulan.id ORDER BY t_neraca.id DESC LIMIT 1) UNION ALL
    				(SELECT 'pdrb_prov', 'Pertumbuhan Ekonomi', des_triwulan, tahun, yy, tanda, poin, sebelumnya, 'persen', '', '" . $lang . "' AS lang FROM t_pdrb_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'miskin', 'Kemiskinan', m_bulan.bulan, t_miskin_prov.tahun, t_miskin_prov.p0_kotadesa, t_miskin_prov.tanda, t_miskin_prov.poin, t_miskin_prov.sebelumnya, 'persen', 'poin', '" . $lang . "' AS lang FROM t_miskin_prov JOIN m_bulan ON t_miskin_prov.bulan = m_bulan.id ORDER BY t_miskin_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'gini_ratio', 'Gini Ratio', m_bulan.bulan, t_giniratio.tahun, t_giniratio.gr_desakota, t_giniratio.tanda, t_giniratio.poin, t_giniratio.sebelumnya, '', 'poin', '" . $lang . "' AS lang FROM t_giniratio JOIN m_bulan ON t_giniratio.bulan = m_bulan.id ORDER BY t_giniratio.id DESC LIMIT 1) UNION ALL
    				(SELECT 'naker', 'Tingkat Pengangguran Terbuka / TPT', m_bulan.bulan, t_naker_prov.tahun, t_naker_prov.tpt, t_naker_prov.tanda, t_naker_prov.poin, t_naker_prov.sebelumnya, 'persen', 'poin', '" . $lang . "' AS lang FROM t_naker_prov JOIN m_bulan ON t_naker_prov.bulan = m_bulan.id ORDER BY t_naker_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipm', 'Indeks Pembangunan Manusia / IPM', 'tahun' , tahun, ipm, tanda, ipm_growth, sebelumnya, '', 'poin', '" . $lang . "' AS lang FROM t_ipm_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'skm', 'Indeks Persepsi Kualitas Pelayanan', trw , tahun, ipkp, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipak', 'Indeks Persepsi Anti Korupsi', trw , tahun, ipak, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1)";
		} else if ($lang == "en") {
			$sql = "(SELECT 'rpjpn' AS id, 'Indikator Utama\nPembangunan Daerah\nProvinsi Jawa Tengah' AS indikator, '' AS bulan, '' AS tahun, '0.00' AS nilai, '' AS tanda, '0.00' AS poin, '' AS sebelumnya, '' AS satuan, '' AS delta, 'en' AS lang FROM ots_ind_rpjpn ORDER BY id_indikator DESC LIMIT 1) UNION ALL
					(SELECT 'inflasi', 'Inflation', m_bulan.bulan, ots_inflasi.tahun, ots_inflasi.month, ots_inflasi.sign, ots_inflasi.poin, ots_inflasi.month_before, 'percent', '', '" . $lang . "' FROM ots_inflasi JOIN m_bulan ON ots_inflasi.bulan = m_bulan.id ORDER BY ots_inflasi.id DESC LIMIT 1) UNION ALL
    				(SELECT 'ntp', 'Farmers Exchange Rate / NTP', m_bulan.bulan, t_ntp.tahun, t_ntp.ntp, t_ntp.sign, t_ntp.poin, t_ntp.month_before, '', '%', '" . $lang . "' AS lang FROM t_ntp JOIN m_bulan ON t_ntp.bulan = m_bulan.id ORDER BY t_ntp.id DESC LIMIT 1) UNION ALL 
    				(SELECT 'ekspor', 'Export', m_bulan.bulan, ots_ekspor.tahun, ots_ekspor.nilai_ekspor, ots_ekspor.sign, ots_ekspor.poin, ots_ekspor.month_before, 'Million US$', '%', '" . $lang . "' AS lang FROM ots_ekspor JOIN m_bulan ON ots_ekspor.bulan = m_bulan.id ORDER BY ots_ekspor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'impor', 'Import', m_bulan.bulan, ots_impor.tahun, ots_impor.nilai_impor, ots_impor.sign, ots_impor.poin, ots_impor.month_before, 'Million US$', '%', '" . $lang . "' AS lang FROM ots_impor JOIN m_bulan ON ots_impor.bulan = m_bulan.id ORDER BY ots_impor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'neraca', 'Balance of Trade', m_bulan.bulan, t_neraca.tahun, t_neraca.nilai, t_neraca.sign, '', '', 'Million US$', '', '" . $lang . "' AS lang FROM t_neraca JOIN m_bulan ON t_neraca.bulan = m_bulan.id ORDER BY t_neraca.id DESC LIMIT 1) UNION ALL
    				(SELECT 'pdrb_prov', 'Economic Growth', des_triwulan, tahun, yy, sign, poin, q_before, 'percent', '', '" . $lang . "' AS lang FROM t_pdrb_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'miskin', 'Poverty', m_bulan.bulan, t_miskin_prov.tahun, t_miskin_prov.p0_kotadesa, t_miskin_prov.sign, t_miskin_prov.poin, t_miskin_prov.month_before, 'percent', 'poin', '" . $lang . "' AS lang FROM t_miskin_prov JOIN m_bulan ON t_miskin_prov.bulan = m_bulan.id ORDER BY t_miskin_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'gini_ratio', 'Gini Ratio', m_bulan.bulan, t_giniratio.tahun, t_giniratio.gr_desakota, t_giniratio.sign, t_giniratio.poin, t_giniratio.month_before, '', 'poin', '" . $lang . "' AS lang FROM t_giniratio JOIN m_bulan ON t_giniratio.bulan = m_bulan.id ORDER BY t_giniratio.id DESC LIMIT 1) UNION ALL
    				(SELECT 'naker', 'Open Unemployment Rate / TPT', m_bulan.bulan, t_naker_prov.tahun, t_naker_prov.tpt, t_naker_prov.sign, t_naker_prov.poin, t_naker_prov.month_before, 'percent', 'poin', '" . $lang . "' AS lang FROM t_naker_prov JOIN m_bulan ON t_naker_prov.bulan = m_bulan.id ORDER BY t_naker_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipm', 'Human Development Index / IPM', 'tahun' , tahun, ipm, sign, ipm_growth, sebelumnya, '', 'poin', '" . $lang . "' AS lang FROM t_ipm_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'skm', 'Service Quality Perception Index', trw , tahun, ipkp, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipak', 'Anti Corruption Perception Index', trw , tahun, ipak, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1)";
		}
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		$arrImage = array("image" => "https://10.bpsjateng.my.id/api_image/banner.png", "link" => "https://jateng.bps.go.id");
		$arrMaklumat = array("image" => "https://10.bpsjateng.my.id/api_image/maklumat.png", "link" => "https://ppid.bps.go.id/app/konten/3300/Standar-Layanan-Informasi-Publik.html#pills-1");

		return $response->withJson(["status" => "success", "image" => $arrImage, "maklumat" => $arrMaklumat, "data" => $result], 200);
	});

	$app->get("/dashboard3/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$sql = "(SELECT 'iup' AS id, 'Indikator Utama\nPembangunan Daerah\nProvinsi Jawa Tengah' AS indikator, '' AS bulan, '' AS tahun, '0.00' AS nilai, '' AS tanda, '0.00' AS poin, '' AS sebelumnya, '' AS satuan, '' AS delta, '" . $lang . "' AS lang FROM ots_ind_rpjpn ORDER BY id_indikator DESC LIMIT 1) UNION ALL
					(SELECT 'inflasi', 'Inflasi', m_bulan.bulan, ots_inflasi.tahun, ots_inflasi.month, ots_inflasi.tanda, ots_inflasi.poin, ots_inflasi.sebelumnya, 'persen', '', '" . $lang . "' FROM ots_inflasi JOIN m_bulan ON ots_inflasi.bulan = m_bulan.id ORDER BY ots_inflasi.id DESC LIMIT 1) UNION ALL 
    				(SELECT 'ntp', 'Nilai Tukar Petani / NTP', m_bulan.bulan, t_ntp.tahun, t_ntp.ntp, t_ntp.tanda, t_ntp.poin, t_ntp.sebelumnya, '', '%', '" . $lang . "' AS lang FROM t_ntp JOIN m_bulan ON t_ntp.bulan = m_bulan.id ORDER BY t_ntp.id DESC LIMIT 1) UNION ALL 
    				(SELECT 'ekspor', 'Ekspor', m_bulan.bulan, ots_ekspor.tahun, ots_ekspor.nilai_ekspor, ots_ekspor.tanda, ots_ekspor.poin, ots_ekspor.sebelumnya, 'Juta US$', '%', '" . $lang . "' AS lang FROM ots_ekspor JOIN m_bulan ON ots_ekspor.bulan = m_bulan.id ORDER BY ots_ekspor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'impor', 'Impor', m_bulan.bulan, ots_impor.tahun, ots_impor.nilai_impor, ots_impor.tanda, ots_impor.poin, ots_impor.sebelumnya, 'Juta US$', '%', '" . $lang . "' AS lang FROM ots_impor JOIN m_bulan ON ots_impor.bulan = m_bulan.id ORDER BY ots_impor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'neraca', 'Neraca Perdagangan', m_bulan.bulan, t_neraca.tahun, t_neraca.nilai, t_neraca.tanda, '', '', 'Juta US$', '', '" . $lang . "' AS lang FROM t_neraca JOIN m_bulan ON t_neraca.bulan = m_bulan.id ORDER BY t_neraca.id DESC LIMIT 1) UNION ALL
    				(SELECT 'pdrb_prov', 'Pertumbuhan Ekonomi', des_triwulan, tahun, yy, tanda, poin, sebelumnya, 'persen', '', '" . $lang . "' AS lang FROM t_pdrb_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'miskin', 'Kemiskinan', m_bulan.bulan, t_miskin_prov.tahun, t_miskin_prov.p0_kotadesa, t_miskin_prov.tanda, t_miskin_prov.poin, t_miskin_prov.sebelumnya, 'persen', 'poin', '" . $lang . "' AS lang FROM t_miskin_prov JOIN m_bulan ON t_miskin_prov.bulan = m_bulan.id ORDER BY t_miskin_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'gini_ratio', 'Gini Ratio', m_bulan.bulan, t_giniratio.tahun, t_giniratio.gr_desakota, t_giniratio.tanda, t_giniratio.poin, t_giniratio.sebelumnya, '', 'poin', '" . $lang . "' AS lang FROM t_giniratio JOIN m_bulan ON t_giniratio.bulan = m_bulan.id ORDER BY t_giniratio.id DESC LIMIT 1) UNION ALL
    				(SELECT 'naker', 'Tingkat Pengangguran Terbuka / TPT', m_bulan.bulan, t_naker_prov.tahun, t_naker_prov.tpt, t_naker_prov.tanda, t_naker_prov.poin, t_naker_prov.sebelumnya, 'persen', 'poin', '" . $lang . "' AS lang FROM t_naker_prov JOIN m_bulan ON t_naker_prov.bulan = m_bulan.id ORDER BY t_naker_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipm', 'Indeks Pembangunan Manusia / IPM', 'tahun' , tahun, ipm, tanda, ipm_growth, sebelumnya, '', 'poin', '" . $lang . "' AS lang FROM t_ipm_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'skm', 'Indeks Persepsi Kualitas Pelayanan', trw , tahun, ipkp, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipak', 'Indeks Persepsi Anti Korupsi', trw , tahun, ipak, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1)";
		} else if ($lang == "en") {
			$sql = "(SELECT 'iup' AS id, 'Indikator Utama\nPembangunan Daerah\nProvinsi Jawa Tengah' AS indikator, '' AS bulan, '' AS tahun, '0.00' AS nilai, '' AS tanda, '0.00' AS poin, '' AS sebelumnya, '' AS satuan, '' AS delta, 'en' AS lang FROM ots_ind_rpjpn ORDER BY id_indikator DESC LIMIT 1) UNION ALL
					(SELECT 'inflasi', 'Inflation', m_bulan.bulan, ots_inflasi.tahun, ots_inflasi.month, ots_inflasi.sign, ots_inflasi.poin, ots_inflasi.month_before, 'percent', '', '" . $lang . "' FROM ots_inflasi JOIN m_bulan ON ots_inflasi.bulan = m_bulan.id ORDER BY ots_inflasi.id DESC LIMIT 1) UNION ALL
    				(SELECT 'ntp', 'Farmers Exchange Rate / NTP', m_bulan.bulan, t_ntp.tahun, t_ntp.ntp, t_ntp.sign, t_ntp.poin, t_ntp.month_before, '', '%', '" . $lang . "' AS lang FROM t_ntp JOIN m_bulan ON t_ntp.bulan = m_bulan.id ORDER BY t_ntp.id DESC LIMIT 1) UNION ALL 
    				(SELECT 'ekspor', 'Export', m_bulan.bulan, ots_ekspor.tahun, ots_ekspor.nilai_ekspor, ots_ekspor.sign, ots_ekspor.poin, ots_ekspor.month_before, 'Million US$', '%', '" . $lang . "' AS lang FROM ots_ekspor JOIN m_bulan ON ots_ekspor.bulan = m_bulan.id ORDER BY ots_ekspor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'impor', 'Import', m_bulan.bulan, ots_impor.tahun, ots_impor.nilai_impor, ots_impor.sign, ots_impor.poin, ots_impor.month_before, 'Million US$', '%', '" . $lang . "' AS lang FROM ots_impor JOIN m_bulan ON ots_impor.bulan = m_bulan.id ORDER BY ots_impor.id DESC LIMIT 1) UNION ALL
    				(SELECT 'neraca', 'Balance of Trade', m_bulan.bulan, t_neraca.tahun, t_neraca.nilai, t_neraca.sign, '', '', 'Million US$', '', '" . $lang . "' AS lang FROM t_neraca JOIN m_bulan ON t_neraca.bulan = m_bulan.id ORDER BY t_neraca.id DESC LIMIT 1) UNION ALL
    				(SELECT 'pdrb_prov', 'Economic Growth', des_triwulan, tahun, yy, sign, poin, q_before, 'percent', '', '" . $lang . "' AS lang FROM t_pdrb_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'miskin', 'Poverty', m_bulan.bulan, t_miskin_prov.tahun, t_miskin_prov.p0_kotadesa, t_miskin_prov.sign, t_miskin_prov.poin, t_miskin_prov.month_before, 'percent', 'poin', '" . $lang . "' AS lang FROM t_miskin_prov JOIN m_bulan ON t_miskin_prov.bulan = m_bulan.id ORDER BY t_miskin_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'gini_ratio', 'Gini Ratio', m_bulan.bulan, t_giniratio.tahun, t_giniratio.gr_desakota, t_giniratio.sign, t_giniratio.poin, t_giniratio.month_before, '', 'poin', '" . $lang . "' AS lang FROM t_giniratio JOIN m_bulan ON t_giniratio.bulan = m_bulan.id ORDER BY t_giniratio.id DESC LIMIT 1) UNION ALL
    				(SELECT 'naker', 'Open Unemployment Rate / TPT', m_bulan.bulan, t_naker_prov.tahun, t_naker_prov.tpt, t_naker_prov.sign, t_naker_prov.poin, t_naker_prov.month_before, 'percent', 'poin', '" . $lang . "' AS lang FROM t_naker_prov JOIN m_bulan ON t_naker_prov.bulan = m_bulan.id ORDER BY t_naker_prov.id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipm', 'Human Development Index / IPM', 'tahun' , tahun, ipm, sign, ipm_growth, sebelumnya, '', 'poin', '" . $lang . "' AS lang FROM t_ipm_prov ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'skm', 'Service Quality Perception Index', trw , tahun, ipkp, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1) UNION ALL
    				(SELECT 'ipak', 'Anti Corruption Perception Index', trw , tahun, ipak, '', '0.00', '', '', '', '" . $lang . "' AS lang FROM ots_skd WHERE kab LIKE '3300%' ORDER BY id DESC LIMIT 1)";
		}
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		$arrImage = array("image" => "https://10.jateng.pro/api_image/banner.png", "link" => "https://jateng.bps.go.id");
		$arrMaklumat = array("image" => "https://10.jateng.pro/api_image/maklumat.png", "link" => "https://ppid.bps.go.id/app/konten/3300/Standar-Layanan-Informasi-Publik.html#pills-1");

		return $response->withJson(["status" => "success", "image" => $arrImage, "maklumat" => $arrMaklumat, "data" => $result], 200);
	});

	// INFLASI //

	$app->get("/inflasi_prov/{lang}", function (Request $request, Response $response, $args) {
		$sql = "SELECT tahun, des_bulan FROM ots_inflasi ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Inflasi Provinsi Jawa Tengah dan Nasional\n" . $bulan . " " . $tahun . " (%)";
			$kolom = "Inflasi:Jateng:Nasional";
			$tahunKalender = "Tahun Kalender";
		} else if ($lang == "en") {
			$judul = "Jawa Tengah Province and National Inflation\n" . $bulan . " " . $tahun . " (%)";
			$kolom = "Inflation:Jateng:National";
			$tahunKalender = "Calendar Year";
		}
		$sql = "SELECT 'Month to Month' AS isi1, month AS isi2, nas_month AS isi3 FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS a UNION ALL
                SELECT 'Year on Year', yoy, nas_yoy FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS b UNION ALL
                SELECT '" . $tahunKalender . "', tahunan, nas_th FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS c";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/inflasi_prov_series/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			if ($tahun > 2019) {
				$sqlCek = "SELECT tahun FROM ots_inflasi WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
				$tabel = "ots_inflasi";
			} else {
				$sqlCek = "SELECT tahun FROM t_inflasi WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
				$tabel = "t_inflasi";
			}
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Inflasi Provinsi Jawa Tengah, " . $tahun;
			$kolom = "Keterangan:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
			$tahunKalender = "Tahun Kalender";
		} else if ($lang == "en") {
			$judul = "Inflation of Jawa Tengah Province, " . $tahun;
			$kolom = "Description:January:February:March:April:May:June:July:August:September:October:November:December";
			$tahunKalender = "Calendar Year";
		}

		for ($i = 1; $i <= 12; $i++) {
			$sql = "SELECT 'Month to Month' AS isi1, month AS isi2 FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT 'Year on Year', yoy FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $tahunKalender . "', tahunan FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => $tabel], 200);
	});

	$app->get("/inflasi_6_kota/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];

		//Cek Tahun
		do {
			if ($tahun > 2019) {
				$sqlCek = "SELECT tahun FROM ots_inflasi WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
				$tabel = "ots_inflasi";
			} else {
				$sqlCek = "SELECT tahun FROM t_inflasi WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
				$tabel = "t_inflasi";
			}
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if ($tahun > 2023) {
			if (($lang == "in" || $lang == "id")) {
				$judul = "Inflasi 9 Kota di Provinsi Jawa Tengah Tahun " . $tahun;
				$kolom = "Kabupaten/Kota:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
				$kolom1 = "Month to Month:Year on Year";
			} else if ($lang == "en") {
				$judul = "Inflation of 9 Cities in Jawa Tengah Province, " . $tahun;
				$kolom = "Regency/Municipality:January:February:March:April:May:June:July:August:September:October:November:December";
				$kolom1 = "Month to Month:Year on Year";
			}
		} else {
			if (($lang == "in" || $lang == "id")) {
				$judul = "Inflasi 9 Kota di Provinsi Jawa Tengah Tahun " . $tahun;
				$kolom = "Kabupaten/Kota:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
				$kolom1 = "Month to Month:Year on Year:Tahun Kalender";
			} else if ($lang == "en") {
				$judul = "Inflation of 9 Cities in Jawa Tengah Province, " . $tahun;
				$kolom = "Regency/Municipality:January:February:March:April:May:June:July:August:September:October:November:December";
				$kolom1 = "Month to Month:Year on Year:Calendar year";
			}
		}


		for ($i = 1; $i <= 12; $i++) {
			if ($tahun > 2023) {
				$sql = "SELECT 'Cilacap' AS isi1, cilacap AS isi2, cilacap_yoy AS isi3 FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
        	            SELECT 'Purwokerto', purwokerto, purwokerto_yoy FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
        	            SELECT 'Kabupaten Wonosobo', wonosobo, wonosobo_yoy FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
        	            SELECT 'Kabupaten Wonogiri', wonogiri, wonogiri_yoy FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
        	            SELECT 'Kabupaten Rembang', rembang, rembang_yoy FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i UNION ALL
        	            SELECT 'Kudus', kudus, kudus_yoy FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
        	            SELECT 'Kota Surakarta', surakarta, surakarta_yoy FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
        	            SELECT 'Kota Semarang', semarang, semarang_yoy FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
        	            SELECT 'Kota Tegal', tegal, tegal_yoy FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
        	            SELECT 'Jawa Tengah', month, yoy FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS j";
			} else {
				$sql = "SELECT 'Cilacap' AS isi1, cilacap AS isi2, cilacap_yoy AS isi3, cilacap_th AS isi4 FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
        	            SELECT 'Purwokerto', purwokerto, purwokerto_yoy, purwokerto_th FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
        	            SELECT 'Kudus', kudus, kudus_yoy, kudus_th FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
        	            SELECT 'Kota Surakarta', surakarta, surakarta_yoy, surakarta_th FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
        	            SELECT 'Kota Semarang', semarang, semarang_yoy, semarang_th FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
        	            SELECT 'Kota Tegal', tegal, tegal_yoy, tegal_th FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f";
			}
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => $tabel], 200);
	});

	$app->get("/inflasi_ibu_kota/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM ots_inflasi ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Inflasi Ibu Kota Provinsi di Pulau Jawa,\n" . $bulan . " " . $tahun;
			$kolom = "Nama Kota:Month to Month:Year on Year:Tahun Kalender";
		} else if ($lang == "en") {
			$judul = "Provincial Capital Inflation in Java Island,\n" . $bulan . " " . $tahun;
			$kolom = "Cities:Month to Month:Year on Year:Calendar Year";
		}

		$sql = "SELECT 'Semarang' AS isi1, semarang AS isi2, semarang_yoy AS isi3, semarang_th AS isi4 FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS a UNION ALL
                SELECT 'DKI Jakarta', dki, dki_yoy, dki_th FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS b UNION ALL
                SELECT 'Serang', serang, serang_yoy, serang_th FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS c UNION ALL
                SELECT 'Bandung', bandung, bandung_yoy, bandung_th FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS d UNION ALL
                SELECT 'Yogyakarta', yogyakarta, yogyakarta_yoy, yogyakarta_th FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS e UNION ALL
                SELECT 'Surabaya', surabaya, surabaya_yoy, surabaya_th FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS f";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/inflasi_ibu_kota/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];

		//Cek Tahun
		do {
			if ($tahun > 2019) {
				$sqlCek = "SELECT tahun FROM ots_inflasi WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
				$tabel = "ots_inflasi";
			} else {
				$sqlCek = "SELECT tahun FROM t_inflasi WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
				$tabel = "t_inflasi";
			}
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Inflasi Ibu Kota Provinsi di Pulau Jawa, " . $tahun;
			$kolom = "Nama Ibu Kota:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
			$kolom1 = "Month to Month:Year on Year:Tahun Kalender";
		} else if ($lang == "en") {
			$judul = "Provincial Capital Inflation in Java Island, " . $tahun;
			$kolom = "Capital Cities:January:February:March:April:May:June:July:August:September:October:November:December";
			$kolom1 = "Month to Month:Year on Year:Calendar Year";
		}

		for ($i = 1; $i <= 12; $i++) {
			$sql = "SELECT 'Semarang' AS isi1, semarang AS isi2, semarang_yoy AS isi3, semarang_th AS isi4 FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
    	            SELECT 'DKI Jakarta', dki, dki_yoy, dki_th FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
    	            SELECT 'Serang', serang, serang_yoy, serang_th FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
    	            SELECT 'Bandung', bandung, bandung_yoy, bandung_th FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
    	            SELECT 'Yogyakarta', yogyakarta, yogyakarta_yoy, yogyakarta_th FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
    	            SELECT 'Surabaya', surabaya, surabaya_yoy, surabaya_th FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => $tabel], 200);
	});

	$app->get("/inflasi_kelompok/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM ots_inflasi ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Inflasi Jawa Tengah Menurut Kelompok Komoditas,\n" . $bulan . " " . $tahun;
			$kolom = " :Kelompok Komoditas:Nilai (%)";
			$sql = "SELECT 'makanan' AS isi1, 'Makanan, Minuman, dan Tembakau' AS isi2, makanan AS isi3 FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'sandang', 'Pakaian dan Alas Kaki', pakaian FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'perumahan', 'Perumahan, Air, Listrik, Gas, dan Bahan Bakar Lainnya', perumahan FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'perlengkapan', 'Perlengkapan, Peralatan, dan Pemeliharaan Rutin Rumah Tangga', perlengkapan FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS d UNION ALL
                    SELECT 'kesehatan', 'Kesehatan', kesehatan FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS e UNION ALL
                    SELECT 'transportasi', 'Transportasi', transportasi FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS f UNION ALL
                    SELECT 'informasi', 'Informasi, Komunikasi, dan Jasa Keuangan', informasi FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS g UNION ALL
                    SELECT 'rekreasi', 'Rekreasi, Olahraga, dan Budaya', rekreasi FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS h UNION ALL
                    SELECT 'pendidikan', 'Pendidikan', pendidikan FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS i UNION ALL
                    SELECT 'restoran', 'Penyediaan Makanan dan Minuman/Restoran', restoran FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS j UNION ALL
                    SELECT 'lainnya', 'Perawatan Pribadi dan Jasa Lainnya', lainnya FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS k";
		} else if ($lang == "en") {
			$judul = "Inflation of Jawa Tengah Province by Group of Expenditures,\n" . $bulan . " " . $tahun;
			$kolom = " :Expenditures:Value (%)";
			$sql = "SELECT 'makanan' AS isi1, 'Foods, Drinks, and Tobacco' AS isi2, makanan AS isi3 FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'sandang', 'Cloth and Footwear', pakaian FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'perumahan', 'Housing, Water, Electricity, and Household Fuels', perumahan FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'perlengkapan', 'Household Equipments, Tools, and Routine Maintenance', perlengkapan FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS d UNION ALL
                    SELECT 'kesehatan', 'Health', kesehatan FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS e UNION ALL
                    SELECT 'transportasi', 'Transportation', transportasi FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS f UNION ALL
                    SELECT 'informasi', 'Information, Communication, and Financial Service', informasi FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS g UNION ALL
                    SELECT 'rekreasi', 'Recreation, Sport, and Culture', rekreasi FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS h UNION ALL
                    SELECT 'pendidikan', 'Education', pendidikan FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS i UNION ALL
                    SELECT 'restoran', 'Food and Beverage Provider/Restaurant', restoran FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS j UNION ALL
                    SELECT 'lainnya', 'Personal Care and Other Services', lainnya FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS k";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/inflasi_kelompok/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];

		//Cek Tahun
		do {
			if ($tahun > 2019) {
				$sqlCek = "SELECT tahun FROM ots_inflasi WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
				$tabel = "ots_inflasi";
			} else {
				$sqlCek = "SELECT tahun FROM t_inflasi WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
				$tabel = "t_inflasi";
			}
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Inflasi Jawa Tengah Menurut Kelompok Komoditas Tahun " . $tahun;
			$kolom = "Kelompok Komoditas:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
			if ($tahun > 2019) {
				$sql = "SELECT 'Makanan, Minuman, dan Tembakau' AS isi1, makanan AS isi2 FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT 'Pakaian dan Alas Kaki', pakaian FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT 'Perumahan, Air, Listrik, Gas, dan Bahan Bakar Lainnya', perumahan FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT 'Perlengkapan, Peralatan, dan Pemeliharaan Rutin Rumah Tangga', perlengkapan FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT 'Kesehatan', kesehatan FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                        SELECT 'Transportasi', transportasi FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                        SELECT 'Informasi, Komunikasi, dan Jasa Keuangan', informasi FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                        SELECT 'Rekreasi, Olahraga, dan Budaya', rekreasi FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                        SELECT 'Pendidikan', pendidikan FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i UNION ALL
                        SELECT 'Penyediaan Makanan dan Minuman/Restoran', restoran FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS j UNION ALL
                        SELECT 'Perawatan Pribadi dan Jasa Lainnya', lainnya FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS k";
			} else {
				$sql = "SELECT 'Bahan Makanan' AS isi1, makanan AS isi2 FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT 'Makanan Jadi, Minuman, Rokok, dan Tembakau', minuman FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT 'Perumahan, Air, Listrik, Gas, dan Bahan Bakar', perumahan FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT 'Sandang', sandang FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT 'Kesehatan', kesehatan FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                        SELECT 'Pendidikan, Rekreasi, dan Olahraga', pendidikan FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                        SELECT 'Transportasi, Komunikasi, dan Jasa Keuangan', transportasi FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g";
			}
		} else if ($lang == "en") {
			$judul = "Inflation of Jawa Tengah Province by Group of Expenditures, " . $tahun;
			$kolom = "Group of Expenditures:January:February:March:April:May:June:July:August:September:October:November:December";
			if ($tahun > 2019) {
				$sql = "SELECT 'Foods, Drinks, and Tobacco' AS isi1, makanan AS isi2 FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT 'Cloth and Footwear', pakaian FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT 'Housing, Water, Electricity, and Household Fuels', perumahan FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT 'Household Equipments, Tools, and Routine Maintenance', perlengkapan FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT 'Health', kesehatan FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                        SELECT 'Transportation', transportasi FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                        SELECT 'Information, Communication, and Financial Servic', informasi FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                        SELECT 'Recreation, Sport, and Culture', rekreasi FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                        SELECT 'Education', pendidikan FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i UNION ALL
                        SELECT 'Food and Beverage Provider/Restaurant', restoran FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS j UNION ALL
                        SELECT 'Personal Care and Other Services', lainnya FROM (SELECT * FROM ots_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS k";
			} else {
				$sql = "SELECT 'Food Stuff' AS isi1, makanan AS isi2 FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT 'Prepared Food, Beverage, Cigarette, and Tobacco', minuman FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT 'Housing, Water, Electricity, Gas, and Fuel', perumahan FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT 'Clothing', sandang FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT 'Medical Care', kesehatan FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                        SELECT 'Education, Recreation, and Sport', pendidikan FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                        SELECT 'Transportation, Communication, and Financial Services', transportasi FROM (SELECT * FROM t_inflasi WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g";
			}
		}

		for ($i = 1; $i <= 12; $i++) {
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => $tabel], 200);
	});

	$app->get("/inflasi_penyumbang/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM ots_inflasi ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Komoditas Utama Penyumbang Inflasi\n" . $bulan . " " . $tahun . ":Komoditas Utama Penyumbang Deflasi\n" . $bulan . " " . $tahun;
			$kolom = "Komoditas:Andil:";
		} else if ($lang == "en") {
			$judul = "Inflation Main Commodity\n" . $bulan . " " . $tahun . ":Deflation Main Commodity\n" . $bulan . " " . $tahun;
			$kolom = "Commodity:Share:";
		}

		$sqlInflasi = "SELECT inflasi_1 AS isi1, inflasi_1_andil AS isi2, '' AS isi3  FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS a UNION ALL
                        SELECT inflasi_2, inflasi_2_andil, '' FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS b UNION ALL
                        SELECT inflasi_3, inflasi_3_andil, '' FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS c UNION ALL
                        SELECT inflasi_4, inflasi_4_andil, '' FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS d UNION ALL
                        SELECT inflasi_5, inflasi_5_andil, '' FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS e UNION ALL
                        SELECT deflasi_1, deflasi_1_andil, '' FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS f UNION ALL
                        SELECT deflasi_2, deflasi_2_andil, '' FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS g UNION ALL
                        SELECT deflasi_3, deflasi_3_andil, '' FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS h UNION ALL
                        SELECT deflasi_4, deflasi_4_andil, '' FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS i UNION ALL
                        SELECT deflasi_5, deflasi_5_andil, '' FROM (SELECT * FROM ots_inflasi ORDER BY id DESC LIMIT 1) AS j";
		$stmt = $this->db->prepare($sqlInflasi);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/inflasi_penyumbang/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];

		//Cek Tahun
		if ($tahun > 2019) {
			$sqlCek = "SELECT tahun FROM ots_inflasi WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$tabel = "ots_inflasi";
		} else {
			$sqlCek = "SELECT tahun FROM t_inflasi WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$tabel = "t_inflasi";
		}
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Komoditas Utama Penyumbang Inflasi dan Deflasi Jawa Tengah, " . $tahun;
			$kolom = "Bulan:Komoditas Utama Penyumbang Inflasi:Komoditas Utama Penyumbang Deflasi";
			$kolom1 = "Komoditas:Andil";
			$bulan = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
		} else if ($lang == "en") {
			$judul = "Inflation and Deflation Main Commodities in Jawa Tengah Province, " . $tahun;
			$kolom = "Month:Inflation Main Commodities:Deflation Main Commodities";
			$kolom1 = "Commodities:Share";
			$bulan = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
		}

		for ($i = 1; $i <= 12; $i++) {
			$sql = "SELECT inflasi_1 AS isi1, inflasi_1_andil AS isi2  FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT inflasi_2, inflasi_2_andil FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT inflasi_3, inflasi_3_andil FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT inflasi_4, inflasi_4_andil FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                    SELECT inflasi_5, inflasi_5_andil FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                    SELECT deflasi_1, deflasi_1_andil FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                    SELECT deflasi_2, deflasi_2_andil FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                    SELECT deflasi_3, deflasi_3_andil FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                    SELECT deflasi_4, deflasi_4_andil FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i UNION ALL
                    SELECT deflasi_5, deflasi_5_andil FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS j";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $bulan[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => $tabel], 200);
	});

	// NTP //

	$app->get("/ntp_prov/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_ntp ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "NTP Umum Jawa Tengah,\n" . $bulan . " " . $tahun . ":NTP Per Subsektor Jawa Tengah,\n" . $bulan . " " . $tahun;
			$kolom = "Keterangan:Nilai:";
			$sql = "SELECT 'Indeks yang diterima petani (It)' AS isi1, it AS isi2, '' AS isi3 FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Indeks yang dibayar petani (Ib)', ib, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Nilai Tukar Petani (NTP)', ntp, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'Tanaman Pangan', tanaman_pangan, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS d UNION ALL
                    SELECT 'Hortikultura', hortikultura, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS e UNION ALL
                    SELECT 'Tanaman Perkebunan Rakyat', tpr, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS f UNION ALL
                    SELECT 'Peternakan', peternakan, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS g UNION ALL
                    SELECT 'Perikanan', ikan_total, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS h UNION ALL
                    SELECT '     Perikanan Tangkap', ikan_nelayan, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS i UNION ALL
                    SELECT '     Perikanan Budidaya', ikan_budidaya, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS j";
		} else if ($lang == "en") {
			$judul = "General NTP of Jawa Tengah Province,\n" . $bulan . " " . $tahun . ":NTP by Subsector of Jawa Tengah Province,\n" . $bulan . " " . $tahun;
			$kolom = "Description:Value:";
			$sql = "SELECT 'Prices Received by Farmers Indices (It)' AS isi1, it AS isi2, '' AS isi3 FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Prices Paid by Farmers Indices (Ib)', ib, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Farmer\'s Terms of Trade (NTP)', ntp, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'Food Crops', tanaman_pangan, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS d UNION ALL
                    SELECT 'Horticulture', hortikultura, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS e UNION ALL
                    SELECT 'Smallholders Estate Crops', tpr, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS f UNION ALL
                    SELECT 'Animal Husbandry', peternakan, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS g UNION ALL
                    SELECT 'Fishery', ikan_total, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS h UNION ALL
                    SELECT '     Capture Fisheries', ikan_nelayan, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS i UNION ALL
                    SELECT '     Aquaculture', ikan_budidaya, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS j";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ntp_prov/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];

		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_ntp WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "NTP Umum dan NTP Per Subsektor Provinsi Jawa Tengah Tahun " . $tahun;
			$judul1 = "NTP Umum:NTP Per Subsektor";
			$kolom = "Keterangan:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
			$sql = "SELECT 'Indeks yang diterima petani (It)' AS isi1, it AS isi2 FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT 'Indeks yang dibayar petani (Ib)', ib FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT 'Nilai Tukar Petani (NTP)', ntp FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT 'Tanaman Pangan', tanaman_pangan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                    SELECT 'Hortikultura', hortikultura FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                    SELECT 'Tanaman Perkebunan Rakyat', tpr FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                    SELECT 'Peternakan', peternakan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                    SELECT 'Perikanan', ikan_total FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                    SELECT '     Perikanan Tangkap', ikan_nelayan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i UNION ALL
                    SELECT '     Perikanan Budidaya', ikan_budidaya FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS j";
		} else if ($lang == "en") {
			$judul = "General NTP and NTP by Subsector of Jawa Tengah Province, " . $tahun;
			$judul1 = "General NTP:NTP by Subsector";
			$kolom = "Description:January:February:March:April:May:June:July:August:September:October:November:December";
			$sql = "SELECT 'Prices Received by Farmers Indices (It)' AS isi1, it AS isi2 FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT 'Prices Paid by Farmers Indices (Ib)', ib FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT 'Farmer\'s Terms of Trade (NTP)', ntp FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT 'Food Crops', tanaman_pangan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                    SELECT 'Horticulture', hortikultura FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                    SELECT 'Smallholders Estate Crops', tpr FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                    SELECT 'Animal Husbandry', peternakan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                    SELECT 'Fishery', ikan_total FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                    SELECT '     Capture Fisheries', ikan_nelayan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i UNION ALL
                    SELECT '     Aquaculture', ikan_budidaya FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS j";
		}

		for ($i = 1; $i <= 12; $i++) {
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "judul1" => $judul1, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_ntp"], 200);
	});

	$app->get("/ntp_penyumbang/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_ntp ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Komoditas Penyumbang NTP Jawa Tengah\n" . $bulan . " " . $tahun;
			$kolom = "Komoditas:Andil:";
			$tanamanpangan = "Tanaman Pangan";
			$horti = "Hortikultura";
			$tpr = "Tanaman Perkebunan Rakyat";
			$ternak = "Peternakan";
			$ikantangkap = "Perikanan Tangkap";
			$ikanbudi = "Perikanan Budidaya";
		} else if ($lang == "en") {
			$judul = "Share of NTP Commodity in Jawa Tengah Province,\n" . $bulan . " " . $tahun;
			$kolom = "Commodity:Share:";
			$tanamanpangan = "Food Crops";
			$horti = "Horticulture";
			$tpr = "Smallholders Estate Crops";
			$ternak = "Animal Husbandry";
			$ikantangkap = "Capture Fisheries";
			$ikanbudi = "Aquaculture";
		}

		$sql = "SELECT '" . $tanamanpangan . "' AS isi1, '' AS isi2, '' AS isi3 FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS a UNION ALL
	            SELECT tanaman_pangan_1, tanaman_pangan_1_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS b UNION ALL
	            SELECT tanaman_pangan_2, tanaman_pangan_2_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS c UNION ALL
                SELECT '" . $horti . "', '', '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS d UNION ALL
	            SELECT hortikultura_1, hortikultura_1_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS e UNION ALL
	            SELECT hortikultura_2, hortikultura_2_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS f UNION ALL
                SELECT '" . $tpr . "', '', '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS g UNION ALL
	            SELECT tpr_1, tpr_1_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS h UNION ALL
	            SELECT tpr_2, tpr_2_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS i UNION ALL
                SELECT '" . $ternak . "', '', '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS j UNION ALL
	            SELECT peternakan_1, peternakan_1_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS k UNION ALL
	            SELECT peternakan_2, peternakan_2_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS l UNION ALL
                SELECT '" . $ikantangkap . "', '', '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS m UNION ALL
	            SELECT ikan_nelayan_1, ikan_nelayan_1_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS n UNION ALL
	            SELECT ikan_nelayan_2, ikan_nelayan_2_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS o UNION ALL
                SELECT '" . $ikanbudi . "', '', '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS p UNION ALL
                SELECT ikan_budidaya_1, ikan_budidaya_1_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS q UNION ALL
	            SELECT ikan_budidaya_2, ikan_budidaya_2_andil, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS r";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ntp_penyumbang/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];

		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_ntp WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Komoditas Penyumbang NTP Jawa Tengah Tahun " . $tahun;
			$kolom = "Bulan:Tanaman Pangan:Hortikultura:TPR:Peternakan:Perikanan Tangkap:Perikanan Budidaya";
			$kolom1 = "Komoditas:Andil";
			$bulan = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
		} else if ($lang == "en") {
			$judul = "Share of NTP Commodity in Jawa Tengah Province, " . $tahun;
			$kolom = "Month:Food Crops:Horticulture:Smallholders Estate Crops:Animal Husbandry:Capture Fisheries:Aquculture";
			$kolom1 = "Commodity:Share";
			$bulan = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
		}

		for ($i = 1; $i <= 12; $i++) {
			$sql = "SELECT tanaman_pangan_1 AS isi1, tanaman_pangan_1_andil AS isi2 FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT tanaman_pangan_2, tanaman_pangan_2_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT hortikultura_1, hortikultura_1_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                    SELECT hortikultura_2, hortikultura_2_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                    SELECT tpr_1, tpr_1_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                    SELECT tpr_2, tpr_2_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i UNION ALL
                    SELECT peternakan_1, peternakan_1_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS k UNION ALL
                    SELECT peternakan_2, peternakan_2_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS l UNION ALL
                    SELECT ikan_nelayan_1, ikan_nelayan_1_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS n UNION ALL
                    SELECT ikan_nelayan_2, ikan_nelayan_2_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS o UNION ALL
                    SELECT ikan_budidaya_1, ikan_budidaya_1_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS q UNION ALL
                    SELECT ikan_budidaya_2, ikan_budidaya_2_andil FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS r";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $bulan[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_ntp"], 200);
	});

	$app->get("/ntp_prov_jawa/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_ntp ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];
		if (($lang == "in" || $lang == "id")) {
			$judul = "NTP Provinsi di Pulau Jawa\n" . $bulan . " " . $tahun;
			$kolom = "Provinsi:Nilai:";
			$indo = "Nasional";
		} else if ($lang == "en") {
			$judul = "NTP Provinces in Java Island,\n" . $bulan . " " . $tahun;
			$kolom = "Province:Value:";
			$indo = "National";
		}

		$sql = "SELECT 'Jawa Tengah' AS isi1, ntp AS isi2, '' AS isi3 FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS a UNION ALL
	            SELECT 'DKI Jakarta', dki, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS b UNION ALL
	            SELECT 'Jawa Barat', jabar, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS c UNION ALL
	            SELECT 'DIY Yogyakarta', diy, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS d UNION ALL
	            SELECT 'Jawa Timur', jatim, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS e UNION ALL
	            SELECT 'Banten', banten, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS f UNION ALL
	            SELECT '" . $indo . "', nasional, '' FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS g";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ntp_prov_jawa/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_ntp WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "NTP Provinsi di Pulau Jawa Tahun " . $tahun;
			$kolom = "Provinsi:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
			$indo = "Nasional";
		} else if ($lang == "en") {
			$judul = "NTP Province in Java Island, " . $tahun;
			$kolom = "Province:January:February:March:April:May:June:July:August:September:October:November:December";
			$indo = "National";
		}

		for ($i = 1; $i <= 12; $i++) {
			$sql = "SELECT 'Jawa Tengah' AS isi1, ntp AS isi2 FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT 'DKI Jakarta', dki FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT 'Jawa Barat', jabar FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT 'DIY Yogyakarta', diy FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                    SELECT 'Jawa Timur', jatim FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                    SELECT 'Banten', banten FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                    SELECT '" . $indo . "', nasional FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_ntp"], 200);
	});

	$app->get("/ntup/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_ntp ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Nilai Tukar Usaha Pertanian Jawa Tengah,\n" . $bulan . " " . $tahun;
			$kolom = " :Keterangan:Nilai";
			$sql = "SELECT 'ntup' AS isi1, 'Nilai Tukar Usaha Pertanian' AS isi2, ntup AS isi3 FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS a UNION ALL
    	            SELECT 'tanaman_pangan', 'Tanaman Pangan', ntup_tanaman_pangan FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS b UNION ALL
    	            SELECT 'hortikultura', 'Hortikultura', ntup_hortikultura FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS c UNION ALL
    	            SELECT 'tpr', 'Tanaman Perkebunan Rakyat', ntup_tpr FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS d UNION ALL
    	            SELECT 'peternakan', 'Peternakan', ntup_peternakan FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS e UNION ALL
    	            SELECT 'ikan_total','Perikanan', ntup_ikan_total FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS f UNION ALL
    	            SELECT 'ikan_nelayan', 'Perikanan Tangkap', ntup_ikan_nelayan FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS g UNION ALL
    	            SELECT 'ikan_budidaya', 'Perikanan Budidaya', ntup_ikan_budidaya FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS h";
		} else if ($lang == "en") {
			$judul = "Agricultural Terms of Trade Jawa Tengah Province,\n" . $bulan . " " . $tahun;
			$kolom = " :Description:Value";
			$sql = "SELECT 'ntup' AS isi1, 'Agricultural Terms of Trade' AS isi2, ntup AS isi3 FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS a UNION ALL
    	            SELECT 'tanaman_pangan', 'Food Crops', ntup_tanaman_pangan FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS b UNION ALL
    	            SELECT 'hortikultura', 'Horticulture', ntup_hortikultura FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS c UNION ALL
    	            SELECT 'tpr', 'Smallholders Estate Crops', ntup_tpr FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS d UNION ALL
    	            SELECT 'peternakan', 'Animal Husbandry', ntup_peternakan FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS e UNION ALL
    	            SELECT 'ikan_total','Fishery', ntup_ikan_total FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS f UNION ALL
    	            SELECT 'ikan_nelayan', 'Capture Fisheries', ntup_ikan_nelayan FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS g UNION ALL
    	            SELECT 'ikan_budidaya', 'Aquaculture', ntup_ikan_budidaya FROM (SELECT * FROM t_ntp ORDER BY id DESC LIMIT 1) AS h";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ntup/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_ntp WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Nilai Tukar Usaha Pertanian Jawa Tengah Tahun " . $tahun;
			$kolom = "Komoditas:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
			$sql = "SELECT 'Nilai Tukar Usaha Pertanian' AS isi1, ntup AS isi2 FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT 'Tanaman Pangan', ntup_tanaman_pangan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT 'Hortikultura', ntup_hortikultura FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT 'Tanaman Perkebunan Rakyat', ntup_tpr FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                    SELECT 'Peternakan', ntup_peternakan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                    SELECT 'Perikanan', ntup_ikan_total FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                    SELECT '   Perikanan Tangkap', ntup_ikan_nelayan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                    SELECT '   Perikanan Budidaya', ntup_ikan_budidaya FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g";
		} else if ($lang == "en") {
			$judul = "Agricultural Terms of Trade Jawa Tengah Province, " . $tahun;
			$kolom = "Commodity:January:February:March:April:May:June:July:August:September:October:November:December";
			$sql = "SELECT 'Agricultural Terms of Trade' AS isi1, ntup AS isi2 FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT 'Food Crops', ntup_tanaman_pangan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT 'Horticulture', ntup_hortikultura FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT 'Smallholders Estate Crops', ntup_tpr FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                    SELECT 'Animal Husbandry', ntup_peternakan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                    SELECT 'Fishery', ntup_ikan_total FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                    SELECT '   Capture Fisheries', ntup_ikan_nelayan FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                    SELECT '   Aquaculture', ntup_ikan_budidaya FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g";
		}

		for ($i = 1; $i <= 12; $i++) {
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_ntp"], 200);
	});

	$app->get("/ntp_series/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_ntp WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Series NTP dan NTUP Provinsi Jawa Tengah, " . $tahun;
			$kolom = "Keterangan:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
			$sql = "SELECT 'Nilai Tukar Petani' AS isi1, ntp AS isi2 FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT 'Nilai Tukar Usaha Pertanian', ntup FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b";
		} else if ($lang == "en") {
			$judul = "Series of NTP and NTUP Jawa Tengah Province, " . $tahun;
			$kolom = "Description:January:February:March:April:May:June:July:August:September:October:November:December";
			$sql = "SELECT 'Farmer\'s Terms of Trade' AS isi1, ntp AS isi2 FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT 'Agricultural Terms of Trade', ntup FROM (SELECT * FROM t_ntp WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b";
		}

		for ($i = 1; $i <= 12; $i++) {
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_ntp"], 200);
	});

	// EKSPOR //

	$app->get("/ekspor_komoditas/{bulan}/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$bulanTemp = $args["bulan"];
		$tahunTemp = $args["tahun"];
		$lang = $args["lang"];
		if ($bulanTemp == "0" && $tahunTemp == "0") {
			$sql = "SELECT tahun, des_bulan FROM ots_ekspor ORDER BY id DESC LIMIT 1";
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$result = $stmt->fetch();
			$tahun = $result['tahun'];
			$bulan = $result['des_bulan'];
			$syarat = "ORDER BY id DESC LIMIT 1";
			$tabel = "ots_ekspor";
		} else {
			if ($tahunTemp > 2018) {
				$tabel = "ots_ekspor";
			} else {
				$tabel = "t_ekspor";
			}
			if (($lang == "in" || $lang == "id")) {
				$arrBulan = ["---", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
			} else if ($lang == "en") {
				$arrBulan = ["---", "January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
			}
			$syarat = "WHERE bulan=:bulan AND tahun=:tahun";
			$bulan = $arrBulan[$bulanTemp];
			$tahun = $tahunTemp;
		}

		if ($tabel == "ots_ekspor") {
			if (($lang == "in" || $lang == "id")) {
				$judul = "Ekspor Provinsi Jawa Tengah Menurut Komoditas, " . $bulan . " " . $tahun;
				$kolom = "Komoditas:Ekspor " . $bulan . " " . $tahun . " (Juta US$):Ekspor Januari - " . $bulan . " " . $tahun . " (Juta US$):Peran terhadap total ekspor Non Migas (%)";
			} else if ($lang == "en") {
				$judul = "Export of Jawa Tengah Province by Commodities, " . $bulan . " " . $tahun;
				$kolom = "Commodity:Export " . $bulan . " " . $tahun . " (Million US$):Export January - " . $bulan . " " . $tahun . " (Million US$):Share of total export January - " . $bulan . " " . $tahun . " (%)";
			}
			$sql = "SELECT kom_1 AS isi1, kom_1_nilai AS isi2, kom_1_nilai_sum AS isi3, kom_1_andil_sum AS isi4 FROM (SELECT * FROM ots_ekspor " . $syarat . ") AS a UNION ALL
    	            SELECT kom_2, kom_2_nilai, kom_2_nilai_sum, kom_2_andil_sum FROM (SELECT * FROM ots_ekspor " . $syarat . ") AS b UNION ALL
    	            SELECT kom_3, kom_3_nilai, kom_3_nilai_sum, kom_3_andil_sum FROM (SELECT * FROM ots_ekspor " . $syarat . ") AS c UNION ALL
    	            SELECT kom_4, kom_4_nilai, kom_4_nilai_sum, kom_4_andil_sum FROM (SELECT * FROM ots_ekspor " . $syarat . ") AS d UNION ALL
    	            SELECT kom_5, kom_5_nilai, kom_5_nilai_sum, kom_5_andil_sum FROM (SELECT * FROM ots_ekspor " . $syarat . ") AS e UNION ALL
    	            SELECT kom_6, kom_6_nilai, kom_6_nilai_sum, kom_6_andil_sum FROM (SELECT * FROM ots_ekspor " . $syarat . ") AS f UNION ALL
    	            SELECT kom_7, kom_7_nilai, kom_7_nilai_sum, kom_7_andil_sum FROM (SELECT * FROM ots_ekspor " . $syarat . ") AS g UNION ALL
    	            SELECT kom_8, kom_8_nilai, kom_8_nilai_sum, kom_8_andil_sum FROM (SELECT * FROM ots_ekspor " . $syarat . ") AS h UNION ALL
    	            SELECT kom_9, kom_9_nilai, kom_9_nilai_sum, kom_9_andil_sum FROM (SELECT * FROM ots_ekspor " . $syarat . ") AS i UNION ALL
    	            SELECT kom_10, kom_10_nilai, kom_10_nilai_sum, kom_10_andil_sum FROM (SELECT * FROM ots_ekspor " . $syarat . ") AS j";
		} else {
			if (($lang == "in" || $lang == "id")) {
				$judul = "Ekspor Provinsi Jawa Tengah Menurut Komoditas, " . $bulan . " Tahun " . $tahun;
				$kolom = "Komoditas:Nilai (Juta US$):Andil (%)";
				$sql = "SELECT 'Tekstil dan barang tekstil' AS isi1, tekstil_nilai AS isi2, tekstil_share AS isi3 FROM (SELECT * FROM t_ekspor " . $syarat . ") AS a UNION ALL
        	            SELECT 'Kayu dan barang dari kayu', kayu_nilai, kayu_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS b UNION ALL
        	            SELECT 'Bermacam barang hasil pabrik', pabrik_nilai, pabrik_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS c UNION ALL
        	            SELECT 'Mesin dan pesawat mekanik', mesin_nilai, mesin_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS d UNION ALL
        	            SELECT 'Produk Mineral', mineral_nilai, mineral_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS e UNION ALL
        	            SELECT 'Produk Kimia', kimia_nilai, kimia_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS f UNION ALL
        	            SELECT 'Lainnya', lainnya, lainnya_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS g";
			} else if ($lang == "en") {
				$judul = "Export of Jawa Tengah Province by Commodities, " . $bulan . " " . $tahun;
				$kolom = "Commodity:Value (Million US$):Share (%)";
				$sql = "SELECT 'Textiles and textile goods' AS isi1, tekstil_nilai AS isi2, tekstil_share AS isi3 FROM (SELECT * FROM t_ekspor " . $syarat . ") AS a UNION ALL
        	            SELECT 'Wood and wood goods', kayu_nilai, kayu_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS b UNION ALL
        	            SELECT 'Various kinds of manufactured goods', pabrik_nilai, pabrik_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS c UNION ALL
        	            SELECT 'Engine and mechanical plane', mesin_nilai, mesin_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS d UNION ALL
        	            SELECT 'Mineral Products', mineral_nilai, mineral_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS e UNION ALL
        	            SELECT 'Chemical Products', kimia_nilai, kimia_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS f UNION ALL
        	            SELECT 'Others', lainnya, lainnya_share FROM (SELECT * FROM t_ekspor " . $syarat . ") AS g";
			}
		}
		$stmt = $this->db->prepare($sql);
		if ($bulanTemp == "0" && $tahunTemp == "0") {
			$stmt->execute();
		} else {
			$stmt->execute([":tahun" => $tahunTemp, ":bulan" => $bulanTemp]);
		}
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result, "tabel" => $tabel], 200);
	});

	$app->get("/ekspor_migas/{lang}", function (Request $request, Response $response, $args) {
		$sql = "SELECT tahun, des_bulan FROM ots_ekspor ORDER BY id DESC LIMIT 1";
		$lang = $args["lang"];
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Ekspor Migas dan Non Migas Provinsi Jawa Tengah Bulan " . $bulan . " " . $tahun;
			$kolom = "Komoditas:Nilai:";
			$migas = "Migas (Juta US$)";
			$nonmigas = "Non Migas (Juta US$)";
			$kum = "Kumulatif Tahunan";
		} else if ($lang == "en") {
			$judul = "Export of Oil and Gas and Non-Oil and Gas Jawa Tengah Province, " . $bulan . " " . $tahun;
			$kolom = "Commodity:Value:";
			$migas = "Oil and Gas (Million US$)";
			$nonmigas = "Non-Oil and Gas (Million US$)";
			$kum = "Yearly Cumulative";
		}

		$sql = "SELECT '" . $migas . "' AS isi1, '' AS isi2, '' AS isi3 FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS a UNION ALL
	            SELECT des_bulan, migas, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS b UNION ALL
	            SELECT '" . $kum . "', migas_kum, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS c UNION ALL
	            SELECT '" . $nonmigas . "', '', '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS d UNION ALL
	            SELECT des_bulan, nonmigas, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS e UNION ALL
	            SELECT '" . $kum . "', nonmigas_kum, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS f";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ekspor_migas/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			if ($tahun > 2018) {
				$tabel = "ots_ekspor";
			} else {
				$tabel = "t_ekspor";
			}
			$sqlCek = "SELECT tahun FROM " . $tabel . " WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Ekspor Migas dan Nonmigas Provinsi Jawa Tengah Tahun " . $tahun;
			$judul1 = "Migas (Juta US$):Non Migas (Juta US$)";
			$kolom = "Keterangan:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
			$bulanan = "Bulanan";
			$kum = "Kumulatif Tahunan";
		} else if ($lang == "en") {
			$judul = "Export of Oil and Gas and Non-Oil and Gas Jawa Tengah Province, " . $tahun;
			$judul1 = "Oil and Gas (Million US$):Non-Oil and Gas (Million US$)";
			$kolom = "Description:January:February:March:April:May:June:July:August:September:October:November:December";
			$bulanan = "Monthly";
			$kum = "Yearly Cumulative";
		}

		for ($i = 1; $i <= 12; $i++) {
			$sql = "SELECT '" . $bulanan . "' AS isi1, migas AS isi2 FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $kum . "', migas_kum FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $bulanan . "', nonmigas FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT '" . $kum . "', nonmigas_kum FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "judul1" => $judul1, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => $tabel], 200);
	});

	$app->get("/ekspor_pertumbuhan/{lang}", function (Request $request, Response $response, $args) {
		$sql = "SELECT tahun, des_bulan FROM ots_ekspor ORDER BY id DESC LIMIT 1";
		$lang = $args["lang"];
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Peningkatan Tertinggi Ekspor Provinsi Jawa Tengah\n" . $bulan . " " . $tahun . ":Penurunan Tertinggi Ekspor Provinsi Jawa Tengah\n" . $bulan . " " . $tahun;
			$kolom = "Komoditas:Nilai (Juta US$):";
		} else if ($lang == "en") {
			$judul = "Highest Growth of Export in Jawa Tengah Province,\n" . $bulan . " " . $tahun . ":Highest Decline of Export in Jawa Tengah Province,\n" . $bulan . " " . $tahun;
			$kolom = "Commodity:Value (Million US$):";
		}

		$sql = "SELECT surplus_1 AS isi1, surplus_1_nilai AS isi2, '' AS isi3  FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS a UNION ALL
                        SELECT surplus_2, surplus_2_nilai, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS b UNION ALL
                        SELECT surplus_3, surplus_3_nilai, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS c UNION ALL
                        SELECT surplus_4, surplus_4_nilai, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS d UNION ALL
                        SELECT surplus_5, surplus_5_nilai, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS e UNION ALL
                        SELECT minus_5 AS isi1, minus_5_nilai AS isi2, '' AS isi3 FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS f UNION ALL
                        SELECT minus_4, minus_4_nilai, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS g UNION ALL
                        SELECT minus_3, minus_3_nilai, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS h UNION ALL
                        SELECT minus_2, minus_2_nilai, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS i UNION ALL
                        SELECT minus_1, minus_1_nilai, '' FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS j";

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ekspor_pertumbuhan/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		if ($tahun > 2018) {
			if (($lang == "in" || $lang == "id")) {
				$judul = "Peningkatan dan Penurunan Tertinggi Ekspor Provinsi Jawa Tengah Tahun " . $tahun;
				$kolom = "Bulan:Peningkatan Tertinggi:Penurunan Tertinggi";
			} else if ($lang == "en") {
				$judul = "Highest Growth and Decline of Export in Jawa Tengah Province, " . $tahun;
				$kolom = "Month:Highest Growth:Highest Decline";
			}
			$sqlCek = "SELECT tahun FROM ots_ekspor WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$tabel = "ots_ekspor";
			$sql = "SELECT surplus_1 AS isi1, surplus_1_nilai AS isi2  FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT surplus_2, surplus_2_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT surplus_3, surplus_3_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT surplus_4, surplus_4_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                    SELECT surplus_5, surplus_5_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                    SELECT minus_5 AS isi1, minus_5_nilai AS isi2 FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                    SELECT minus_4, minus_4_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                    SELECT minus_3, minus_3_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                    SELECT minus_2, minus_2_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i UNION ALL
                    SELECT minus_1, minus_1_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS j";
		} else {
			if (($lang == "in" || $lang == "id")) {
				$judul = "Peningkatan Tertinggi Ekspor Jawa Tengah Provinsi, " . $tahun;
				$kolom = "Bulan:Peningkatan Tertinggi";
			} else if ($lang == "en") {
				$judul = "Highest Growth of Export in Jawa Tengah Province, " . $tahun;
				$kolom = "Month:Highest Growth";
			}
			$sqlCek = "SELECT tahun FROM t_ekspor WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$tabel = "t_ekspor";
			$sql = "SELECT pertumbuhan1_des AS isi1, pertumbuhan1_nilai AS isi2  FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT pertumbuhan2_des, pertumbuhan2_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT pertumbuhan3_des, pertumbuhan3_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT pertumbuhan4_des, pertumbuhan4_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d";
		}
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$kolom1 = "Komoditas:Nilai (Juta US$)";
			$bulan = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
		} else if ($lang == "en") {
			$kolom1 = "Commodity:Value (Million US$)";
			$bulan = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
		}

		for ($i = 1; $i <= 12; $i++) {
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $bulan[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => $tabel], 200);
	});

	$app->get("/ekspor_negara/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM ots_ekspor ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Ekspor Jawa Tengah Menurut Negara Tujuan Utama,\n" . $bulan . " " . $tahun . " (Juta US$)";
			$ue = "Uni Eropa";
			$usa = "Amerika Serikat";
			$jepang = "Jepang";
			$tiongkok = "Tiongkok";
			$korsel = "Korea Selatan";
		} else if ($lang == "en") {
			$judul = "Export of Jawa Tengah Province by Main Destination Country,\n" . $bulan . " " . $tahun . " (Million US$)";
			$ue = "European Union";
			$usa = "United States of America";
			$jepang = "Japan";
			$tiongkok = "China";
			$korsel = "South Korea";
		}
		$kolom = " : : ";

		$sql = "SELECT 'asean' AS negara, 'ASEAN' AS deskripsi, asean_nilai AS nilai, asean_poin AS poin FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS a UNION ALL
                SELECT 'ue', '" . $ue . "', ue_nilai, ue_poin FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS b UNION ALL
                SELECT 'amerika', '" . $usa . "', usa_nilai, usa_poin FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS c UNION ALL
                SELECT 'jepang', '" . $jepang . "', jepang_nilai, jepang_poin FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS d UNION ALL
                SELECT 'china', '" . $tiongkok . "', tiongkok_nilai, tiongkok_poin FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS e UNION ALL
                SELECT 'korsel', '" . $korsel . "', korsel_nilai, korsel_poin FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS f UNION ALL
                SELECT 'india', 'India', india_nilai, india_poin FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS g UNION ALL
                SELECT 'australia', 'Australia', australia_nilai, australia_poin FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS h UNION ALL
                SELECT 'taiwan', 'Taiwan', taiwan_nilai, taiwan_poin FROM (SELECT * FROM ots_ekspor ORDER BY id DESC LIMIT 1) AS i";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ekspor_negara/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];

		if (($lang == "in" || $lang == "id")) {
			$ue = "Uni Eropa";
			$usa = "Amerika Serikat";
			$jepang = "Jepang";
			$tiongkok = "Tiongkok";
			$korsel = "Korea Selatan";
			$jerman = "Jerman";
		} else if ($lang == "en") {
			$ue = "European Union";
			$usa = "United States of America";
			$jepang = "Japan";
			$tiongkok = "China";
			$korsel = "South Korea";
			$jerman = "German";
		}

		//Cek Tahun
		do {
			if ($tahun > 2018) {
				$tabel = "ots_ekspor";
				$sql = "SELECT 'ASEAN' AS isi1, asean_nilai AS isi2 FROM (SELECT * FROM ots_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT '" . $ue . "', ue_nilai FROM (SELECT * FROM ots_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT '" . $usa . "', usa_nilai FROM (SELECT * FROM ots_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT '" . $jepang . "', jepang_nilai FROM (SELECT * FROM ots_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT '" . $tiongkok . "', tiongkok_nilai FROM (SELECT * FROM ots_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                        SELECT '" . $korsel . "', korsel_nilai FROM (SELECT * FROM ots_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                        SELECT 'India', india_nilai FROM (SELECT * FROM ots_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                        SELECT 'Australia', australia_nilai FROM (SELECT * FROM ots_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                        SELECT 'Taiwan', taiwan_nilai FROM (SELECT * FROM ots_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i";
			} else {
				$tabel = "t_ekspor";
				$sql = "SELECT '" . $usa . "' AS isi1, usa_nilai AS isi2 FROM (SELECT * FROM t_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT '" . $jepang . "', jepang_nilai FROM (SELECT * FROM t_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT '" . $tiongkok . "', tiongkok_nilai FROM (SELECT * FROM t_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT '" . $jerman . "', jerman_nilai FROM (SELECT * FROM t_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT 'Malaysia', malaysia_nilai FROM (SELECT * FROM t_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                        SELECT '" . $korsel . "', korsel_nilai FROM (SELECT * FROM t_ekspor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f";
			}
			$sqlCek = "SELECT tahun FROM " . $tabel . " WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Ekspor Jawa Tengah Menurut Negara Tujuan Utama Tahun " . $tahun . " (Juta US$)";
			$kolom = "Negara:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
		} else if ($lang == "en") {
			$judul = "Export of Jawa Tengah Province by Main Destination Country, " . $tahun . " (Million US$)";
			$kolom = "Country:January:February:March:April:May:June:July:August:September:October:November:December";
		}

		for ($i = 1; $i <= 12; $i++) {
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => $tabel], 200);
	});

	// IMPOR //

	$app->get("/impor_komoditas/{bulan}/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$bulanTemp = $args["bulan"];
		$tahunTemp = $args["tahun"];
		$lang = $args["lang"];
		if ($bulanTemp == "0" && $tahunTemp == "0") {
			$sqlTemp = "SELECT tahun, des_bulan FROM ots_impor ORDER BY id DESC LIMIT 1";
			$stmt = $this->db->prepare($sqlTemp);
			$stmt->execute();
			$result = $stmt->fetch();
			$tahun = $result['tahun'];
			$bulan = $result['des_bulan'];
			$syarat = "ORDER BY id DESC LIMIT 1";
			$tabel = "ots_impor";
		} else {
			if ($tahunTemp > 2018) {
				$tabel = "ots_impor";
			} else {
				$tabel = "t_impor";
			}
			if (($lang == "in" || $lang == "id")) {
				$arrBulan = ["---", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
			} else if ($lang == "en") {
				$arrBulan = ["---", "January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
			}
			$syarat = "WHERE bulan=:bulan AND tahun=:tahun";
			$bulan = $arrBulan[$bulanTemp];
			$tahun = $tahunTemp;
		}

		if (($lang == "in" || $lang == "id")) {
			$judul = "Impor Provinsi Jawa Tengah Menurut Komoditas, " . $bulan . " " . $tahun;
		} else if ($lang == "en") {
			$judul = "Import of Jawa Tengah Province by Commodities, " . $bulan . " " . $tahun;
		}

		if ($tabel == "ots_impor") {
			if (($lang == "in" || $lang == "id")) {
				$kolom = "Komoditas:Impor " . $bulan . " " . $tahun . ":Impor Januari - " . $bulan . " " . $tahun . ":Peran terhadap total impor Non Migas (%)";
				$namakom = "m_hs2.nama";
			} else if ($lang == "en") {
				$kolom = "Commodity:Import " . $bulan . " " . $tahun . ":Import January - " . $bulan . " " . $tahun . ":Share of total import January - " . $bulan . " " . $tahun . " (%)";
				$namakom = "m_hs2.name";
			}
			$sql = "(SELECT " . $namakom . " AS isi1, ots_impor.kom_1_nilai AS isi2, ots_impor.kom_1_nilai_sum AS isi3, ots_impor.kom_1_andil_sum AS isi4 FROM ots_impor JOIN m_hs2 ON ots_impor.kom_1 = m_hs2.hs " . $syarat . ") UNION ALL
    	            (SELECT " . $namakom . ", ots_impor.kom_2_nilai, ots_impor.kom_2_nilai_sum, ots_impor.kom_2_andil_sum FROM ots_impor JOIN m_hs2 ON ots_impor.kom_2 = m_hs2.hs " . $syarat . ") UNION ALL
    	            (SELECT " . $namakom . ", ots_impor.kom_3_nilai, ots_impor.kom_3_nilai_sum, ots_impor.kom_3_andil_sum FROM ots_impor JOIN m_hs2 ON ots_impor.kom_3 = m_hs2.hs " . $syarat . ") UNION ALL
    	            (SELECT " . $namakom . ", ots_impor.kom_4_nilai, ots_impor.kom_4_nilai_sum, ots_impor.kom_4_andil_sum FROM ots_impor JOIN m_hs2 ON ots_impor.kom_4 = m_hs2.hs " . $syarat . ") UNION ALL
    	            (SELECT " . $namakom . ", ots_impor.kom_5_nilai, ots_impor.kom_5_nilai_sum, ots_impor.kom_5_andil_sum FROM ots_impor JOIN m_hs2 ON ots_impor.kom_5 = m_hs2.hs " . $syarat . ") UNION ALL
    	            (SELECT " . $namakom . ", ots_impor.kom_6_nilai, ots_impor.kom_6_nilai_sum, ots_impor.kom_6_andil_sum FROM ots_impor JOIN m_hs2 ON ots_impor.kom_6 = m_hs2.hs " . $syarat . ") UNION ALL
    	            (SELECT " . $namakom . ", ots_impor.kom_7_nilai, ots_impor.kom_7_nilai_sum, ots_impor.kom_7_andil_sum FROM ots_impor JOIN m_hs2 ON ots_impor.kom_7 = m_hs2.hs " . $syarat . ") UNION ALL
    	            (SELECT " . $namakom . ", ots_impor.kom_8_nilai, ots_impor.kom_8_nilai_sum, ots_impor.kom_8_andil_sum FROM ots_impor JOIN m_hs2 ON ots_impor.kom_8 = m_hs2.hs " . $syarat . ") UNION ALL
    	            (SELECT " . $namakom . ", ots_impor.kom_9_nilai, ots_impor.kom_9_nilai_sum, ots_impor.kom_9_andil_sum FROM ots_impor JOIN m_hs2 ON ots_impor.kom_9 = m_hs2.hs " . $syarat . ") UNION ALL
    	            (SELECT " . $namakom . ", ots_impor.kom_10_nilai, ots_impor.kom_10_nilai_sum, ots_impor.kom_10_andil_sum FROM ots_impor JOIN m_hs2 ON ots_impor.kom_10 = m_hs2.hs " . $syarat . ")";
		} else {
			if (($lang == "in" || $lang == "id")) {
				$kolom = "Komoditas:Nilai (Juta US$):Share (%)";
				$sql = "SELECT 'Produk Mineral' AS isi1, mineral_nilai AS isi2, mineral_share AS isi3 FROM (SELECT * FROM t_impor " . $syarat . ") AS a UNION ALL
        	            SELECT 'Tekstil dan Barang Tekstil', tekstil_nilai, tekstil_share FROM (SELECT * FROM t_impor " . $syarat . ") AS b UNION ALL
        	            SELECT 'Mesin dan Pesawat Mekanik', mesindanpesawat_nilai, mesindanpesawat_share FROM (SELECT * FROM t_impor " . $syarat . ") AS c UNION ALL
        	            SELECT 'Produk Nabati', produknabati_nilai, produknabati_share FROM (SELECT * FROM t_impor " . $syarat . ") AS d UNION ALL
        	            SELECT 'Bahan Makan Olahan, Minuman', bahanmakanolahanminuman_nilai, bahanmakanolahanminuman_share FROM (SELECT * FROM t_impor " . $syarat . ") AS e UNION ALL
        	            SELECT 'Plastik dan Barang dari Plastik, Karet dan Barang dari Karet', plastik_nilai, plastik_share FROM (SELECT * FROM t_impor " . $syarat . ") AS f UNION ALL
        	            SELECT 'Lainnya', lainnya, lainnya_share FROM (SELECT * FROM t_impor " . $syarat . ") AS g";
			} else if ($lang == "en") {
				$kolom = "Commodity:Value (Million US$):Share (%)";
				$sql = "SELECT 'Mineral Products' AS isi1, mineral_nilai AS isi2, mineral_share AS isi3 FROM (SELECT * FROM t_impor " . $syarat . ") AS a UNION ALL
        	            SELECT 'Textiles and Textile Goods', tekstil_nilai, tekstil_share FROM (SELECT * FROM t_impor " . $syarat . ") AS b UNION ALL
        	            SELECT 'Mechanical Engines and Tools', mesindanpesawat_nilai, mesindanpesawat_share FROM (SELECT * FROM t_impor " . $syarat . ") AS c UNION ALL
        	            SELECT 'Vegetable Products', produknabati_nilai, produknabati_share FROM (SELECT * FROM t_impor " . $syarat . ") AS d UNION ALL
        	            SELECT 'Processed Foods, Beverages', bahanmakanolahanminuman_nilai, bahanmakanolahanminuman_share FROM (SELECT * FROM t_impor " . $syarat . ") AS e UNION ALL
        	            SELECT 'Plastics and Plastic Goods, Rubber and Rubber Goods', plastik_nilai, plastik_share FROM (SELECT * FROM t_impor " . $syarat . ") AS f UNION ALL
        	            SELECT 'Others', lainnya, lainnya_share FROM (SELECT * FROM t_impor " . $syarat . ") AS g";
			}
		}

		$stmt = $this->db->prepare($sql);

		if ($bulanTemp == "0" && $tahunTemp == "0") {
			$stmt->execute();
		} else {
			$stmt->execute([":tahun" => $tahunTemp, ":bulan" => $bulanTemp]);
		}
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result, "tabel" => $tabel], 200);
	});

	$app->get("/impor_migas/{lang}", function (Request $request, Response $response, $args) {
		$sql = "SELECT tahun, des_bulan FROM ots_impor ORDER BY id DESC LIMIT 1";
		$lang = $args["lang"];
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Impor Migas dan Non Migas Provinsi Jawa Tengah, " . $bulan . " " . $tahun;
			$kolom = "Komoditas:Nilai:";
			$migas = "Migas (Juta US$)";
			$nonmigas = "Non Migas (Juta US$)";
			$kum = "Kumulatif Tahunan";

		} else if ($lang == "en") {
			$judul = "Import of Oil and Gas and Non-Oil and Gas in Jawa Tengah Province, " . $bulan . " " . $tahun;
			$kolom = "Commodity:Value:";
			$migas = "Oil and Gas (Million US$)";
			$nonmigas = "Non-Oil and Gas (Million US$)";
			$kum = "Yearly Cumulative";
		}

		$sql = "SELECT '" . $migas . "' AS isi1, '' AS isi2, '' AS isi3 FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS a UNION ALL
	            SELECT des_bulan, migas, '' FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS b UNION ALL
	            SELECT '" . $kum . "', migas_kum, '' FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS c UNION ALL
	            SELECT '" . $nonmigas . "', '', '' FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS d UNION ALL
	            SELECT des_bulan, nonmigas, '' FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS e UNION ALL
	            SELECT '" . $kum . "', nonmigas_kum, '' FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS f";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/impor_migas/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			if ($tahun > 2018) {
				$tabel = "ots_impor";
			} else {
				$tabel = "t_impor";
			}
			$sqlCek = "SELECT tahun FROM " . $tabel . " WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Impor Migas dan Nonmigas Provinsi Jawa Tengah Tahun " . $tahun;
			$judul1 = "Migas (Juta US$):Non Migas (Juta US$)";
			$kolom = "Keterangan:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
			$bulan = "Bulanan";
			$kum = "Kumulatif Tahunan";
		} else if ($lang == "en") {
			$judul = "Import of Oil and Gas and Non-Oil and Gas in Jawa Tengah Province, " . $tahun;
			$judul1 = "Oil and Gas (Million US$):Non-Oil and Gas (Million US$)";
			$kolom = "Description:January:February:March:April:May:June:July:August:September:October:November:December";
			$bulan = "Monthly";
			$kum = "Yearly Cumulative";
		}

		for ($i = 1; $i <= 12; $i++) {
			$sql = "SELECT '" . $bulan . "' AS isi1, migas AS isi2 FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $kum . "', migas_kum FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $bulan . "', nonmigas FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT '" . $kum . "', nonmigas_kum FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "judul1" => $judul1, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => $tabel], 200);
	});

	$app->get("/impor_pertumbuhan/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM ots_impor ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Peningkatan Tertinggi Impor Provinsi Jawa Tengah\n" . $bulan . " " . $tahun . ":Penurunan Tertinggi Impor Provinsi Jawa Tengah\n" . $bulan . " " . $tahun;
			$kolom = "Komoditas:Nilai (Juta US$):";
			$namakom = "m_hs2.nama";
		} else if ($lang == "en") {
			$judul = "Highest Growth of Import in Jawa Tengah Province,\n" . $bulan . " " . $tahun . ":Highest Decline of Import in Jawa Tengah Province,\n" . $bulan . " " . $tahun;
			$kolom = "Commodity:Value (Million US$):";
			$namakom = "m_hs2.name";
		}

		$sql = "(SELECT " . $namakom . " AS isi1, ots_impor.surplus_1_nilai AS isi2, '' AS isi3 FROM ots_impor JOIN m_hs2 ON ots_impor.surplus_1 = m_hs2.hs ORDER BY id DESC LIMIT 1) UNION ALL
                (SELECT " . $namakom . ", ots_impor.surplus_2_nilai, '' FROM ots_impor JOIN m_hs2 ON ots_impor.surplus_2 = m_hs2.hs ORDER BY id DESC LIMIT 1) UNION ALL
                (SELECT " . $namakom . ", ots_impor.surplus_3_nilai, '' FROM ots_impor JOIN m_hs2 ON ots_impor.surplus_3 = m_hs2.hs ORDER BY id DESC LIMIT 1) UNION ALL
                (SELECT " . $namakom . ", ots_impor.surplus_4_nilai, '' FROM ots_impor JOIN m_hs2 ON ots_impor.surplus_4 = m_hs2.hs ORDER BY id DESC LIMIT 1) UNION ALL
                (SELECT " . $namakom . ", ots_impor.surplus_5_nilai, '' FROM ots_impor JOIN m_hs2 ON ots_impor.surplus_5 = m_hs2.hs ORDER BY id DESC LIMIT 1) UNION ALL
                (SELECT " . $namakom . ", ots_impor.minus_5_nilai, '' FROM ots_impor JOIN m_hs2 ON ots_impor.minus_5 = m_hs2.hs ORDER BY id DESC LIMIT 1) UNION ALL
                (SELECT " . $namakom . ", ots_impor.minus_4_nilai, '' FROM ots_impor JOIN m_hs2 ON ots_impor.minus_4 = m_hs2.hs ORDER BY id DESC LIMIT 1) UNION ALL
                (SELECT " . $namakom . ", ots_impor.minus_3_nilai, '' FROM ots_impor JOIN m_hs2 ON ots_impor.minus_3 = m_hs2.hs ORDER BY id DESC LIMIT 1) UNION ALL
                (SELECT " . $namakom . ", ots_impor.minus_2_nilai, '' FROM ots_impor JOIN m_hs2 ON ots_impor.minus_2 = m_hs2.hs ORDER BY id DESC LIMIT 1) UNION ALL
                (SELECT " . $namakom . ", ots_impor.minus_1_nilai, '' FROM ots_impor JOIN m_hs2 ON ots_impor.minus_1 = m_hs2.hs ORDER BY id DESC LIMIT 1)";

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/impor_pertumbuhan/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		if ($tahun > 2018) {
			if (($lang == "in" || $lang == "id")) {
				$judul = "Peningkatan dan Penurunan Tertinggi Impor Provinsi Jawa Tengah, " . $tahun;
				$kolom = "Bulan:Peningkatan Tertinggi:Penurunan Tertinggi";
				$namakom = "m_hs2.nama";
			} else if ($lang == "en") {
				$judul = "Highest Growth and Decline of Import in Jawa Tengah Province, " . $tahun;
				$kolom = "Month:Highest Growth:Highest Decline";
				$namakom = "m_hs2.name";
			}
			$sqlCek = "SELECT tahun FROM ots_impor WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$tabel = "ots_impor";
			$sql = "(SELECT " . $namakom . " AS isi1, ots_impor.surplus_1_nilai AS isi2 FROM " . $tabel . " JOIN m_hs2 ON ots_impor.surplus_1 = m_hs2.hs WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) UNION ALL
                    (SELECT " . $namakom . ", ots_impor.surplus_2_nilai FROM " . $tabel . " JOIN m_hs2 ON ots_impor.surplus_2 = m_hs2.hs WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) UNION ALL
                    (SELECT " . $namakom . ", ots_impor.surplus_3_nilai FROM " . $tabel . " JOIN m_hs2 ON ots_impor.surplus_3 = m_hs2.hs WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) UNION ALL
                    (SELECT " . $namakom . ", ots_impor.surplus_4_nilai FROM " . $tabel . " JOIN m_hs2 ON ots_impor.surplus_4 = m_hs2.hs WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) UNION ALL
                    (SELECT " . $namakom . ", ots_impor.surplus_5_nilai FROM " . $tabel . " JOIN m_hs2 ON ots_impor.surplus_5 = m_hs2.hs WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) UNION ALL
                    (SELECT " . $namakom . ", ots_impor.minus_5_nilai FROM " . $tabel . " JOIN m_hs2 ON ots_impor.minus_5 = m_hs2.hs WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) UNION ALL
                    (SELECT " . $namakom . ", ots_impor.minus_4_nilai FROM " . $tabel . " JOIN m_hs2 ON ots_impor.minus_4 = m_hs2.hs WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) UNION ALL
                    (SELECT " . $namakom . ", ots_impor.minus_3_nilai FROM " . $tabel . " JOIN m_hs2 ON ots_impor.minus_3 = m_hs2.hs WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) UNION ALL
                    (SELECT " . $namakom . ", ots_impor.minus_2_nilai FROM " . $tabel . " JOIN m_hs2 ON ots_impor.minus_2 = m_hs2.hs WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) UNION ALL
                    (SELECT " . $namakom . ", ots_impor.minus_1_nilai FROM " . $tabel . " JOIN m_hs2 ON ots_impor.minus_1 = m_hs2.hs WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id)";
		} else {
			if (($lang == "in" || $lang == "id")) {
				$judul = "Peningkatan Tertinggi Impor Provinsi Jawa Tengah, " . $tahun;
				$kolom = "Bulan:Peningkatan Tertinggi";
			} else if ($lang == "en") {
				$judul = "Highest Growth of Import in Jawa Tengah Province, " . $tahun;
				$kolom = "Month:Highest Growth";
			}
			$sqlCek = "SELECT tahun FROM t_impor WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$tabel = "t_impor";
			$sql = "SELECT pertumbuhan1_des AS isi1, pertumbuhan1_nilai AS isi2  FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT pertumbuhan2_des, pertumbuhan2_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                    SELECT pertumbuhan3_des, pertumbuhan3_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                    SELECT pertumbuhan4_des, pertumbuhan4_nilai FROM (SELECT * FROM " . $tabel . " WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d";
		}
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$kolom1 = "Komoditas:Nilai (Juta US$)";
			$bulan = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
		} else if ($lang == "en") {
			$kolom1 = "Commodity:Value (Million US$)";
			$bulan = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
		}

		for ($i = 1; $i <= 12; $i++) {
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $bulan[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => $tabel], 200);
	});

	$app->get("/impor_negara/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM ots_impor ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Impor Jawa Tengah Menurut Negara Tujuan Utama\n" . $bulan . " " . $tahun . " (Juta US$)";
			$ue = "Uni Eropa";
			$usa = "Amerika Serikat";
			$jepang = "Jepang";
			$tiongkok = "Tiongkok";
			$korsel = "Korea Selatan";
		} else if ($lang == "en") {
			$judul = "Import of Jawa Tengah Province by Main Destination Country,\n" . $bulan . " " . $tahun . " (Million US$)";
			$ue = "European Union";
			$usa = "United States of America";
			$jepang = "Japan";
			$tiongkok = "China";
			$korsel = "South Korea";
		}
		$kolom = " : : ";

		$sql = "SELECT 'asean' AS negara, 'ASEAN' AS deskripsi, asean_nilai AS nilai, asean_poin AS poin FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS a UNION ALL
                SELECT 'ue', '" . $ue . "', ue_nilai, ue_poin FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS b UNION ALL
                SELECT 'china', '" . $tiongkok . "', tiongkok_nilai, tiongkok_poin FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS c UNION ALL
                SELECT 'jepang', '" . $jepang . "', jepang_nilai, jepang_poin FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS d UNION ALL
                SELECT 'amerika','" . $usa . "', usa_nilai, usa_poin FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS e UNION ALL
                SELECT 'india', 'India', india_nilai, india_poin FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS f UNION ALL
                SELECT 'korsel', '" . $korsel . "', korsel_nilai, korsel_poin FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS f UNION ALL
                SELECT 'taiwan', 'Taiwan', taiwan_nilai, taiwan_poin FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS f UNION ALL
                SELECT 'australia', 'Australia', australia_nilai, australia_poin FROM (SELECT * FROM ots_impor ORDER BY id DESC LIMIT 1) AS f";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/impor_negara/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Impor Jawa Tengah Menurut Negara Tujuan Utama\n" . $bulan . " " . $tahun . " (Juta US$)";
			$ue = "Uni Eropa";
			$usa = "Amerika Serikat";
			$jepang = "Jepang";
			$tiongkok = "Tiongkok";
			$korsel = "Korea Selatan";
			$arab = "Arab Saudi";
			$singa = "Singapura";
		} else if ($lang == "en") {
			$judul = "Import of Jawa Tengah Province by Main Destination Country,\n" . $bulan . " " . $tahun . " (Million US$)";
			$ue = "European Union";
			$usa = "United States of America";
			$jepang = "Japan";
			$tiongkok = "China";
			$korsel = "South Korea";
			$arab = "Saudi Arabia";
			$singa = "Singapore";
		}

		//Cek Tahun
		do {
			if ($tahun > 2018) {
				$tabel = "ots_impor";
				$sql = "SELECT 'ASEAN' AS isi1, asean_nilai AS isi2 FROM (SELECT * FROM ots_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT '" . $ue . "', ue_nilai FROM (SELECT * FROM ots_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT '" . $tiongkok . "', tiongkok_nilai FROM (SELECT * FROM ots_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT '" . $jepang . "', jepang_nilai FROM (SELECT * FROM ots_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT '" . $usa . "', usa_nilai FROM (SELECT * FROM ots_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                        SELECT 'India', india_nilai FROM (SELECT * FROM ots_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                        SELECT '" . $korsel . "', korsel_nilai FROM (SELECT * FROM ots_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                        SELECT 'Taiwan', taiwan_nilai FROM (SELECT * FROM ots_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                        SELECT 'Australia', australia_nilai FROM (SELECT * FROM ots_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i";
			} else {
				$tabel = "t_impor";
				$sql = "SELECT '" . $tiongkok . "' AS isi1, tiongkok_nilai AS isi2 FROM (SELECT * FROM t_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT '" . $arab . "', arabsaudi_nilai FROM (SELECT * FROM t_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT 'Malaysia', malaysia_nilai FROM (SELECT * FROM t_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT '" . $usa . "', usa_nilai FROM (SELECT * FROM t_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT '" . $singa . "', singapura_nilai FROM (SELECT * FROM t_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                        SELECT 'Nigeria', nigeria_nilai FROM (SELECT * FROM t_impor WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f";
			}
			$sqlCek = "SELECT tahun FROM " . $tabel . " WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Impor Provinsi Jawa Tengah Menurut Negara Tujuan Utama Tahun " . $tahun . " (Juta US$)";
			$kolom = "Negara:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
		} else if ($lang == "en") {
			$judul = "Import of Jawa Tengah Province by Main Destination Country, " . $tahun . " (Million US$)";
			$kolom = "Country:January:February:March:April:May:June:July:August:September:October:November:December";
		}

		for ($i = 1; $i <= 12; $i++) {
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => $tabel], 200);
	});

	// TEMP DEBUG - REMOVE LATER
	$app->get("/debug_pdrb", function (Request $request, Response $response, $args) {
		$result = [];

		// 1. t_pdrb_prov columns
		$stmt = $this->db->query("DESCRIBE t_pdrb_prov");
		$result['columns'] = $stmt->fetchAll();

		// 2. All master tables
		$stmt = $this->db->query("SHOW TABLES LIKE 'm_%'");
		$result['master_tables'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);

		// 3. Latest row
		$stmt = $this->db->query("SELECT * FROM t_pdrb_prov ORDER BY id DESC LIMIT 1");
		$result['latest_row'] = $stmt->fetch();

		// 4. Count
		$stmt = $this->db->query("SELECT COUNT(*) as cnt FROM t_pdrb_prov");
		$result['count'] = $stmt->fetch();

		return $response->withJson($result, 200);
	});

	// NERACA //

	$app->get("/neraca/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_neraca ORDER BY tahun DESC, CAST(bulan AS UNSIGNED) DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Neraca Perdagangan Provinsi Jawa Tengah (Juta US$)";
			$kolom = "Keterangan:Nilai:";
		} else if ($lang == "en") {
			$judul = "Balance of Trade in Jawa Tengah Province (Million US$)";
			$kolom = "Description:Value:";
		}

		$sql = "SELECT des_bulan AS isi1, nilai AS isi2, '' AS isi3 FROM (SELECT * FROM t_neraca ORDER BY tahun DESC, CAST(bulan AS UNSIGNED) DESC LIMIT 1) AS a UNION ALL
		        SELECT 'Januari - " . $bulan . "', nilai_kum, '' FROM (SELECT * FROM t_neraca ORDER BY tahun DESC, CAST(bulan AS UNSIGNED) DESC LIMIT 1) AS b";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/neraca/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_neraca WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Neraca Perdagangan Provinsi Jawa Tengah, " . $tahun . " (Juta US$)";
			$kolom = "Keterangan:Januari:Februari:Maret:April:Mei:Juni:Juli:Agustus:September:Oktober:November:Desember";
			$nilai = "Nilai";
			$kum = "Kumulatif Tahunan";
		} else if ($lang == "en") {
			$judul = "Balance of Trade in Jawa Tengah Province, " . $tahun . " (Million US$)";
			$kolom = "Description:January:February:March:April:May:June:July:August:September:October:November:December";
			$nilai = "Value";
			$kum = "Yearly Cumulative";
		}

		for ($i = 1; $i <= 12; $i++) {
			$sql = "SELECT '" . $nilai . "' AS isi1, nilai AS isi2 FROM (SELECT * FROM t_neraca WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $kum . "', nilai_kum FROM (SELECT * FROM t_neraca WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("bulan" => $i);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_neraca"], 200);
	});

	// PDRB //

	$app->get("/pdrb_lu_nominal/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "PDRB Provinsi Jawa Tengah Menurut Lapangan Usaha Tahun " . $tahun . " (triliun rupiah)";
			$kolom = "Lapangan Usaha:Harga Berlaku:Harga Konstan";
			$kolom1 = "Trw I:Trw II:Trw III:Trw IV";
			$master = [['x'=>'Pertanian, Kehutanan, dan Perikanan'],['x'=>'Pertambangan dan Penggalian'],['x'=>'Industri Pengolahan'],['x'=>'Pengadaan Listrik dan Gas'],['x'=>'Pengadaan Air, Pengelolaan Sampah, Limbah'],['x'=>'Konstruksi'],['x'=>'Perdagangan Besar dan Eceran; Reparasi Mobil dan Sepeda Motor'],['x'=>'Transportasi dan Pergudangan'],['x'=>'Penyediaan Akomodasi dan Makan Minum'],['x'=>'Informasi dan Komunikasi'],['x'=>'Jasa Keuangan dan Asuransi'],['x'=>'Real Estat'],['x'=>'Jasa Perusahaan'],['x'=>'Administrasi Pemerintahan, Pertahanan dan Jaminan Sosial Wajib'],['x'=>'Jasa Pendidikan'],['x'=>'Jasa Kesehatan dan Kegiatan Sosial'],['x'=>'Jasa Lainnya'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			$judul = "GRDP of Jawa Tengah Province by Industrial Origin, " . $tahun . " (Trillion rupiah)";
			$kolom = "Industrial Origin:Current Market Proce:Constant Market Price";
			$kolom1 = "Q-I:Q-II:Q-III:Q-IV";
			$master = [['x'=>'Agriculture, Forestry and Fisheries'],['x'=>'Mining and Quarrying'],['x'=>'Manufacturing'],['x'=>'Electricity and Gas'],['x'=>'Water Supply, Sewerage, Waste Management'],['x'=>'Construction'],['x'=>'Wholesale and Retail Trade; Repair of Motor Vehicles and Motorcycles'],['x'=>'Transportation and Storage'],['x'=>'Accommodation and Food Service Activities'],['x'=>'Information and Communication'],['x'=>'Financial and Insurance Activities'],['x'=>'Real Estate Activities'],['x'=>'Business Activities'],['x'=>'Public Administration, Defence and Compulsory Social Security'],['x'=>'Education'],['x'=>'Human Health and Social Work Activities'],['x'=>'Other Services Activities'],['x'=>'GRDP']];
		}
		// $master already assigned above
		$trw = ["I", "II", "III", "IV"];

		for ($i = 1; $i <= 4; $i++) {
			$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, a_berlaku AS isi2, a_konstan AS isi3 FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $master[1]['x'] . "', b_berlaku, b_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $master[2]['x'] . "', c_berlaku, c_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS c UNION ALL
                    SELECT '" . $master[3]['x'] . "', d_berlaku, d_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS d UNION ALL
                    SELECT '" . $master[4]['x'] . "', e_berlaku, e_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS e UNION ALL
                    SELECT '" . $master[5]['x'] . "', f_berlaku, f_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS f UNION ALL
                    SELECT '" . $master[6]['x'] . "', g_berlaku, g_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS g UNION ALL
                    SELECT '" . $master[7]['x'] . "', h_berlaku, h_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS h UNION ALL
                    SELECT '" . $master[8]['x'] . "', i_berlaku, i_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS i UNION ALL
                    SELECT '" . $master[9]['x'] . "', j_berlaku, j_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS j UNION ALL
                    SELECT '" . $master[10]['x'] . "', k_berlaku, k_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS k UNION ALL
                    SELECT '" . $master[11]['x'] . "', l_berlaku, l_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS l UNION ALL
                    SELECT '" . $master[12]['x'] . "', mn_berlaku, mn_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS mn UNION ALL
                    SELECT '" . $master[13]['x'] . "', o_berlaku, o_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS o UNION ALL
                    SELECT '" . $master[14]['x'] . "', p_berlaku, p_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS p UNION ALL
                    SELECT '" . $master[15]['x'] . "', q_berlaku, q_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS q UNION ALL
                    SELECT '" . $master[16]['x'] . "', rstu_berlaku, rstu_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS rstu UNION ALL
                    SELECT '" . $master[17]['x'] . "', total_lu_berlaku, total_lu_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS total";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":triwulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("triwulan" => $trw[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('triwulan' => $arr['triwulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_pdrb_prov"], 200);
	});

	$app->get("/pdrb_lu_pertumbuhan/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Pertumbuhan PDRB Jawa Tengah Menurut Lapangan Usaha, " . $tahun . " (%)";
			$kolom = "Lapangan Usaha:Q to Q:Y on Y:C to C";
			$kolom1 = "Trw I:Trw II:Trw III:Trw IV";
			$master = [['x'=>'Pertanian, Kehutanan, dan Perikanan'],['x'=>'Pertambangan dan Penggalian'],['x'=>'Industri Pengolahan'],['x'=>'Pengadaan Listrik dan Gas'],['x'=>'Pengadaan Air, Pengelolaan Sampah, Limbah'],['x'=>'Konstruksi'],['x'=>'Perdagangan Besar dan Eceran; Reparasi Mobil dan Sepeda Motor'],['x'=>'Transportasi dan Pergudangan'],['x'=>'Penyediaan Akomodasi dan Makan Minum'],['x'=>'Informasi dan Komunikasi'],['x'=>'Jasa Keuangan dan Asuransi'],['x'=>'Real Estat'],['x'=>'Jasa Perusahaan'],['x'=>'Administrasi Pemerintahan, Pertahanan dan Jaminan Sosial Wajib'],['x'=>'Jasa Pendidikan'],['x'=>'Jasa Kesehatan dan Kegiatan Sosial'],['x'=>'Jasa Lainnya'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			$judul = "Growth of GRDP by Industrial Origin of Jawa Tengah Province, " . $tahun . " (%)";
			$kolom = "Industrial Origin:Q to Q:Y on Y:C to C";
			$kolom1 = "Q-I:Q-II:Q-III:Q-IV";
			$master = [['x'=>'Agriculture, Forestry and Fisheries'],['x'=>'Mining and Quarrying'],['x'=>'Manufacturing'],['x'=>'Electricity and Gas'],['x'=>'Water Supply, Sewerage, Waste Management'],['x'=>'Construction'],['x'=>'Wholesale and Retail Trade; Repair of Motor Vehicles and Motorcycles'],['x'=>'Transportation and Storage'],['x'=>'Accommodation and Food Service Activities'],['x'=>'Information and Communication'],['x'=>'Financial and Insurance Activities'],['x'=>'Real Estate Activities'],['x'=>'Business Activities'],['x'=>'Public Administration, Defence and Compulsory Social Security'],['x'=>'Education'],['x'=>'Human Health and Social Work Activities'],['x'=>'Other Services Activities'],['x'=>'GRDP']];
		}
		// $master already assigned above
		$trw = ["I", "II", "III", "IV"];

		for ($i = 1; $i <= 4; $i++) {
			$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, a_qq AS isi2, a_yy AS isi3, a_cc AS isi4 FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $master[1]['x'] . "', b_qq, b_yy, b_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $master[2]['x'] . "', c_qq, c_yy, c_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS c UNION ALL
                    SELECT '" . $master[3]['x'] . "', d_qq, d_yy, d_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS d UNION ALL
                    SELECT '" . $master[4]['x'] . "', e_qq, e_yy, e_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS e UNION ALL
                    SELECT '" . $master[5]['x'] . "', f_qq, f_yy, f_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS f UNION ALL
                    SELECT '" . $master[6]['x'] . "', g_qq, g_yy, g_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS g UNION ALL
                    SELECT '" . $master[7]['x'] . "', h_qq, h_yy, h_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS h UNION ALL
                    SELECT '" . $master[8]['x'] . "', i_qq, i_yy, i_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS i UNION ALL
                    SELECT '" . $master[9]['x'] . "', j_qq, j_yy, j_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS j UNION ALL
                    SELECT '" . $master[10]['x'] . "', k_qq, k_yy, k_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS k UNION ALL
                    SELECT '" . $master[11]['x'] . "', l_qq, l_yy, l_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS l UNION ALL
                    SELECT '" . $master[12]['x'] . "', mn_qq, mn_yy, mn_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS mn UNION ALL
                    SELECT '" . $master[13]['x'] . "', o_qq, o_yy, o_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS o UNION ALL
                    SELECT '" . $master[14]['x'] . "', p_qq, p_yy, p_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS p UNION ALL
                    SELECT '" . $master[15]['x'] . "', q_qq, q_yy, q_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS q UNION ALL
                    SELECT '" . $master[16]['x'] . "', rstu_qq, rstu_yy, rstu_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS rstu UNION ALL
                    SELECT '" . $master[17]['x'] . "', total_qq, total_yy, total_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS total";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":triwulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("triwulan" => $trw[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('triwulan' => $arr['triwulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_pdrb_prov"], 200);
	});

	$app->get("/pdrb_lu_distribusi/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Distribusi PDRB Jawa Tengah Menurut Lapangan Usaha Atas Dasar Harga Berlaku, " . $tahun . " (%)";
			$kolom = "Lapangan Usaha:Trw I:Trw II:Trw III:Trw IV";
			$master = [['x'=>'Pertanian, Kehutanan, dan Perikanan'],['x'=>'Pertambangan dan Penggalian'],['x'=>'Industri Pengolahan'],['x'=>'Pengadaan Listrik dan Gas'],['x'=>'Pengadaan Air, Pengelolaan Sampah, Limbah'],['x'=>'Konstruksi'],['x'=>'Perdagangan Besar dan Eceran; Reparasi Mobil dan Sepeda Motor'],['x'=>'Transportasi dan Pergudangan'],['x'=>'Penyediaan Akomodasi dan Makan Minum'],['x'=>'Informasi dan Komunikasi'],['x'=>'Jasa Keuangan dan Asuransi'],['x'=>'Real Estat'],['x'=>'Jasa Perusahaan'],['x'=>'Administrasi Pemerintahan, Pertahanan dan Jaminan Sosial Wajib'],['x'=>'Jasa Pendidikan'],['x'=>'Jasa Kesehatan dan Kegiatan Sosial'],['x'=>'Jasa Lainnya'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			$judul = "Distribution of GDRP by Industrial Origin of Jawa Tengah Province at Current Market Price, " . $tahun . " (%)";
			$kolom = "Industrial Origin:Q-I:Q-II:Q-III:Q-IV";
			$master = [['x'=>'Agriculture, Forestry and Fisheries'],['x'=>'Mining and Quarrying'],['x'=>'Manufacturing'],['x'=>'Electricity and Gas'],['x'=>'Water Supply, Sewerage, Waste Management'],['x'=>'Construction'],['x'=>'Wholesale and Retail Trade; Repair of Motor Vehicles and Motorcycles'],['x'=>'Transportation and Storage'],['x'=>'Accommodation and Food Service Activities'],['x'=>'Information and Communication'],['x'=>'Financial and Insurance Activities'],['x'=>'Real Estate Activities'],['x'=>'Business Activities'],['x'=>'Public Administration, Defence and Compulsory Social Security'],['x'=>'Education'],['x'=>'Human Health and Social Work Activities'],['x'=>'Other Services Activities'],['x'=>'GRDP']];
		}
		// $master already assigned above
		$trw = ["I", "II", "III", "IV"];

		for ($i = 1; $i <= 4; $i++) {
			$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, a_dis AS isi2 FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $master[1]['x'] . "', b_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $master[2]['x'] . "', c_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS c UNION ALL
                    SELECT '" . $master[3]['x'] . "', d_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS d UNION ALL
                    SELECT '" . $master[4]['x'] . "', e_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS e UNION ALL
                    SELECT '" . $master[5]['x'] . "', f_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS f UNION ALL
                    SELECT '" . $master[6]['x'] . "', g_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS g UNION ALL
                    SELECT '" . $master[7]['x'] . "', h_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS h UNION ALL
                    SELECT '" . $master[8]['x'] . "', i_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS i UNION ALL
                    SELECT '" . $master[9]['x'] . "', j_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS j UNION ALL
                    SELECT '" . $master[10]['x'] . "', k_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS k UNION ALL
                    SELECT '" . $master[11]['x'] . "', l_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS l UNION ALL
                    SELECT '" . $master[12]['x'] . "', mn_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS mn UNION ALL
                    SELECT '" . $master[13]['x'] . "', o_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS o UNION ALL
                    SELECT '" . $master[14]['x'] . "', p_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS p UNION ALL
                    SELECT '" . $master[15]['x'] . "', q_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS q UNION ALL
                    SELECT '" . $master[16]['x'] . "', rstu_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS rstu UNION ALL
                    SELECT '" . $master[17]['x'] . "', total_dis_lu FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS total";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":triwulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("triwulan" => $trw[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('triwulan' => $arr['triwulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_pdrb_prov"], 200);
	});

	$app->get("/pdrb_lu_sumber/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Sumber Pertumbuhan Ekonomi Jawa Tengah Menurut Lapangan Usaha, " . $tahun . " (YoY, %)";
			$kolom = "Lapangan Usaha:Trw I:Trw II:Trw III:Trw IV";
			$master = [['x'=>'Pertanian, Kehutanan, dan Perikanan'],['x'=>'Pertambangan dan Penggalian'],['x'=>'Industri Pengolahan'],['x'=>'Pengadaan Listrik dan Gas'],['x'=>'Pengadaan Air, Pengelolaan Sampah, Limbah'],['x'=>'Konstruksi'],['x'=>'Perdagangan Besar dan Eceran; Reparasi Mobil dan Sepeda Motor'],['x'=>'Transportasi dan Pergudangan'],['x'=>'Penyediaan Akomodasi dan Makan Minum'],['x'=>'Informasi dan Komunikasi'],['x'=>'Jasa Keuangan dan Asuransi'],['x'=>'Real Estat'],['x'=>'Jasa Perusahaan'],['x'=>'Administrasi Pemerintahan, Pertahanan dan Jaminan Sosial Wajib'],['x'=>'Jasa Pendidikan'],['x'=>'Jasa Kesehatan dan Kegiatan Sosial'],['x'=>'Jasa Lainnya'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			$judul = "Source of Economic Growth by Industrial Origin of Jawa Tengah Province, " . $tahun . " (YoY, %)";
			$kolom = "Industrial Origin:Q-I:Q-II:Q-III:Q-IV";
			$master = [['x'=>'Agriculture, Forestry and Fisheries'],['x'=>'Mining and Quarrying'],['x'=>'Manufacturing'],['x'=>'Electricity and Gas'],['x'=>'Water Supply, Sewerage, Waste Management'],['x'=>'Construction'],['x'=>'Wholesale and Retail Trade; Repair of Motor Vehicles and Motorcycles'],['x'=>'Transportation and Storage'],['x'=>'Accommodation and Food Service Activities'],['x'=>'Information and Communication'],['x'=>'Financial and Insurance Activities'],['x'=>'Real Estate Activities'],['x'=>'Business Activities'],['x'=>'Public Administration, Defence and Compulsory Social Security'],['x'=>'Education'],['x'=>'Human Health and Social Work Activities'],['x'=>'Other Services Activities'],['x'=>'GRDP']];
		}
		// $master already assigned above
		$trw = ["I", "II", "III", "IV"];

		for ($i = 1; $i <= 4; $i++) {
			$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, a_sbr AS isi2 FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $master[1]['x'] . "', b_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $master[2]['x'] . "', c_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS c UNION ALL
                    SELECT '" . $master[3]['x'] . "', d_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS d UNION ALL
                    SELECT '" . $master[4]['x'] . "', e_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS e UNION ALL
                    SELECT '" . $master[5]['x'] . "', f_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS f UNION ALL
                    SELECT '" . $master[6]['x'] . "', g_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS g UNION ALL
                    SELECT '" . $master[7]['x'] . "', h_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS h UNION ALL
                    SELECT '" . $master[8]['x'] . "', i_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS i UNION ALL
                    SELECT '" . $master[9]['x'] . "', j_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS j UNION ALL
                    SELECT '" . $master[10]['x'] . "', k_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS k UNION ALL
                    SELECT '" . $master[11]['x'] . "', l_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS l UNION ALL
                    SELECT '" . $master[12]['x'] . "', mn_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS mn UNION ALL
                    SELECT '" . $master[13]['x'] . "', o_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS o UNION ALL
                    SELECT '" . $master[14]['x'] . "', p_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS p UNION ALL
                    SELECT '" . $master[15]['x'] . "', q_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS q UNION ALL
                    SELECT '" . $master[16]['x'] . "', rstu_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS rstu";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":triwulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("triwulan" => $trw[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('triwulan' => $arr['triwulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_pdrb_prov"], 200);
	});

	$app->get("/pdrb_pengeluaran_nominal/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "PDRB Jawa Tengah Menurut Pengeluaran Tahun " . $tahun . " (triliun rupiah)";
			$kolom = "Komponen Pengeluaran:Harga Berlaku:Harga Konstan";
			$kolom1 = "Trw I:Trw II:Trw III:Trw IV";
			$master = [['x'=>'Konsumsi Rumah Tangga'],['x'=>'Konsumsi LNPRT'],['x'=>'Konsumsi Pemerintah'],['x'=>'Pembentukan Modal Tetap Bruto'],['x'=>'Ekspor Barang dan Jasa'],['x'=>'Impor Barang dan Jasa'],['x'=>'Net Ekspor'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			$judul = "GRDP by Expenditure of Jawa Tengah Province, " . $tahun . " (trillion rupiah)";
			$kolom = "Type of Expenditure:Current Market Price:Constant Market Price";
			$kolom1 = "Q-I:Q-II:Q-III:Q-IV";
			$master = [['x'=>'Household Consumption'],['x'=>'NPISH Consumption'],['x'=>'Government Consumption'],['x'=>'Gross Fixed Capital Formation'],['x'=>'Exports of Goods and Services'],['x'=>'Imports of Goods and Services'],['x'=>'Net Exports'],['x'=>'GRDP']];
		}
		// $master already assigned above
		$trw = ["I", "II", "III", "IV"];

		for ($i = 1; $i <= 4; $i++) {
			$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, krt_berlaku AS isi2, krt_konstan AS isi3 FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $master[1]['x'] . "', lnprt_berlaku, lnprt_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $master[2]['x'] . "', kp_berlaku, kp_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS c UNION ALL
                    SELECT '" . $master[3]['x'] . "', pmtb_berlaku, pmtb_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS d UNION ALL
                    SELECT '" . $master[4]['x'] . "', ekspor_berlaku, ekspor_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS e UNION ALL
                    SELECT '" . $master[5]['x'] . "', impor_berlaku, impor_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS f UNION ALL
                    SELECT '" . $master[6]['x'] . "', netekspor_berlaku, netekspor_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS g UNION ALL
                    SELECT '" . $master[7]['x'] . "', total_e_berlaku, total_e_konstan FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS h";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":triwulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("triwulan" => $trw[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('triwulan' => $arr['triwulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_pdrb_prov"], 200);
	});

	$app->get("/pdrb_pengeluaran_pertumbuhan/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Pertumbuhan PDRB Jawa Tengah Menurut Pengeluaran. " . $tahun . " (%)";
			$kolom = "Komponen Pengeluaran:Q to Q:Y on Y:C to C";
			$kolom1 = "Trw I:Trw II:Trw III:Trw IV";
			$master = [['x'=>'Konsumsi Rumah Tangga'],['x'=>'Konsumsi LNPRT'],['x'=>'Konsumsi Pemerintah'],['x'=>'Pembentukan Modal Tetap Bruto'],['x'=>'Ekspor Barang dan Jasa'],['x'=>'Impor Barang dan Jasa'],['x'=>'Net Ekspor'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			$judul = "Growth of GRDP by Expenditure of Jawa Tengah Province, " . $tahun . " (%)";
			$kolom = "Type of Expenditure:Q to Q:Y on Y:C to C";
			$kolom1 = "Q-I:Q-II:Q-III:Q-IV";
			$master = [['x'=>'Household Consumption'],['x'=>'NPISH Consumption'],['x'=>'Government Consumption'],['x'=>'Gross Fixed Capital Formation'],['x'=>'Exports of Goods and Services'],['x'=>'Imports of Goods and Services'],['x'=>'Net Exports'],['x'=>'GRDP']];
		}
		// $master already assigned above
		$trw = ["I", "II", "III", "IV"];

		for ($i = 1; $i <= 4; $i++) {
			$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, krt_qq AS isi2, krt_yy AS isi3, krt_cc AS isi4 FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $master[1]['x'] . "', lnprt_qq, lnprt_yy, lnprt_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $master[2]['x'] . "', kp_qq, kp_yy, kp_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS c UNION ALL
                    SELECT '" . $master[3]['x'] . "', pmtb_qq, pmtb_yy, pmtb_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS d UNION ALL
                    SELECT '" . $master[4]['x'] . "', ekspor_qq, ekspor_yy, ekspor_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS e UNION ALL
                    SELECT '" . $master[5]['x'] . "', impor_qq, impor_yy, impor_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS f UNION ALL
                    SELECT '" . $master[6]['x'] . "', netekspor_qq, netekspor_yy, netekspor_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS g UNION ALL
                    SELECT '" . $master[7]['x'] . "', total_p_qq, total_p_yy, total_p_cc FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS total";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":triwulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("triwulan" => $trw[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('triwulan' => $arr['triwulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_pdrb_prov"], 200);
	});

	$app->get("/pdrb_pengeluaran_distribusi/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Distribusi PDRB Jawa Tengah Menurut Pengeluaran Atas Dasar Harga Berlaku, " . $tahun . " (%)";
			$kolom = "Komponen pengeluaran:Trw I:Trw II:Trw III:Trw IV";
			$master = [['x'=>'Konsumsi Rumah Tangga'],['x'=>'Konsumsi LNPRT'],['x'=>'Konsumsi Pemerintah'],['x'=>'Pembentukan Modal Tetap Bruto'],['x'=>'Ekspor Barang dan Jasa'],['x'=>'Impor Barang dan Jasa'],['x'=>'Net Ekspor'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			$judul = "Distribution of GRDP by Expenditure at Current Market Price of Jawa Tengah Province, " . $tahun . " (%)";
			$kolom = "Type of Expenditure:Q-I:Q-II:Q-III:Q-IV";
			$master = [['x'=>'Household Consumption'],['x'=>'NPISH Consumption'],['x'=>'Government Consumption'],['x'=>'Gross Fixed Capital Formation'],['x'=>'Exports of Goods and Services'],['x'=>'Imports of Goods and Services'],['x'=>'Net Exports'],['x'=>'GRDP']];
		}
		// $master already assigned above
		$trw = ["I", "II", "III", "IV"];

		for ($i = 1; $i <= 4; $i++) {
			$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, krt_dis AS isi2 FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $master[1]['x'] . "', lnprt_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $master[2]['x'] . "', kp_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS c UNION ALL
                    SELECT '" . $master[3]['x'] . "', pmtb_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS d UNION ALL
                    SELECT '" . $master[4]['x'] . "', ekspor_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS e UNION ALL
                    SELECT '" . $master[5]['x'] . "', impor_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS f UNION ALL
                    SELECT '" . $master[6]['x'] . "', netekspor_dis FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS g UNION ALL
                    SELECT '" . $master[7]['x'] . "', total_dis_e FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS h";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":triwulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("triwulan" => $trw[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('triwulan' => $arr['triwulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_pdrb_prov"], 200);
	});

	$app->get("/pdrb_pengeluaran_sumber/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Sumber Pertumbuhan Ekonomi Jawa Tengah Menurut Pengeluaran, " . $tahun . " (YoY, %)";
			$kolom = "Komponen Pengeluaran:Trw I:Trw II:Trw III:Trw IV";
			$master = [['x'=>'Konsumsi Rumah Tangga'],['x'=>'Konsumsi LNPRT'],['x'=>'Konsumsi Pemerintah'],['x'=>'Pembentukan Modal Tetap Bruto'],['x'=>'Ekspor Barang dan Jasa'],['x'=>'Impor Barang dan Jasa'],['x'=>'Net Ekspor'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			$judul = "Source of Economic Growth by Expenditure of Jawa Tengah Province, " . $tahun . " (%)";
			$kolom = "Type of Expenditure:Q-I:Q-II:Q-III:Q-IV";
			$master = [['x'=>'Household Consumption'],['x'=>'NPISH Consumption'],['x'=>'Government Consumption'],['x'=>'Gross Fixed Capital Formation'],['x'=>'Exports of Goods and Services'],['x'=>'Imports of Goods and Services'],['x'=>'Net Exports'],['x'=>'GRDP']];
		}
		// $master already assigned above
		$trw = ["I", "II", "III", "IV"];

		for ($i = 1; $i <= 4; $i++) {
			$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, krt_sbr AS isi2 FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS a UNION ALL
                    SELECT '" . $master[1]['x'] . "', lnprt_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS b UNION ALL
                    SELECT '" . $master[2]['x'] . "', kp_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS c UNION ALL
                    SELECT '" . $master[3]['x'] . "', pmtb_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS d UNION ALL
                    SELECT '" . $master[4]['x'] . "', ekspor_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS e UNION ALL
                    SELECT '" . $master[5]['x'] . "', impor_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS f UNION ALL
                    SELECT '" . $master[6]['x'] . "', netekspor_sbr FROM (SELECT * FROM t_pdrb_prov WHERE tahun=:tahun AND id<>0 AND triwulan=:triwulan ORDER BY id) AS g";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun, ":triwulan" => $i]);
			$result = $stmt->fetchAll();
			$result1 = array("triwulan" => $trw[$i - 1]);
			array_push($result1, $result);
			if ($i == 1) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('triwulan' => $arr['triwulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_pdrb_prov"], 200);
	});

	$app->get("/pdrb_kab_nominal/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_kab WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "PDRB Kabupaten/Kota di Jawa Tengah, " . $tahun . " (triliun rupiah)";
			$kolom = "Kabupaten/Kota:Harga Berlaku:Harga Konstan";
		} else if ($lang == "en") {
			$judul = "GRDP of Regency/Municipality in Jawa Tengah Province, " . $tahun . " (trillion rupiah)";
			$kolom = "Regency/Municipality:Current Market Price:Constant Market Price";
		}
		$sql = "SELECT nama_kab AS isi1, pdrb_berlaku AS isi2, pdrb_konstan AS isi3 FROM t_pdrb_kab WHERE tahun=:tahun ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result, "tabel" => "t_pdrb_kab"], 200);
	});

	$app->get("/pdrb_kab_pertumbuhan/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_kab WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Laju Pertumbuhan Ekonomi Kabupaten/Kota di Jawa Tengah, " . $tahun . " (%)";
			$kolom = "Kabupaten/Kota:Nilai:";
		} else if ($lang == "en") {
			$judul = "Rate of Regency/Municipality Economic Growth in Jawa Tengah Province, " . $tahun . " (%)";
			$kolom = "Regency/Municipality:Value:";
		}

		$sql = "SELECT nama_kab AS isi1, pertumbuhan AS isi2 , '' AS isi3 FROM t_pdrb_kab WHERE tahun=:tahun ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result, "tabel" => "t_pdrb_kab"], 200);
	});

	$app->get("/pdrb_kab_distribusi/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_kab WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Distribusi PDRB Kabupaten/Kota Atas Dasar Harga Berlaku di Jawa Tengah, " . $tahun . " (%)";
			$kolom = "Kabupaten/Kota:Nilai:";
		} else if ($lang == "en") {
			$judul = "Distribution of GRDP by Regency/Municipality at Current Market Price in Jawa Tengah Province, " . $tahun . " (%)";
			$kolom = "Regency/Municipality:Value:";
		}

		$sql = "SELECT nama_kab AS isi1, distribusi AS isi2, '' AS isi3 FROM t_pdrb_kab WHERE tahun=:tahun ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result, "tabel" => "t_pdrb_kab"], 200);
	});

	$app->get("/pdrb_kab_perkapita/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_pdrb_kab WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "PDRB Perkapita Kabupaten/Kota di Jawa Tengah, " . $tahun . " (ribu rupiah)";
			$kolom = "Kabupaten/Kota:Nilai:";
		} else if ($lang == "en") {
			$judul = "GRDP per Capita by Regency/Municipality in Jawa Tengah Province, " . $tahun . " (million rupiah)";
			$kolom = "Regency/Municipality:Value:";
		}

		$sql = "SELECT nama_kab AS isi1, perkapita AS isi2, '' AS isi3 FROM t_pdrb_kab WHERE tahun=:tahun ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result, "tabel" => "t_pdrb_kab"], 200);
	});

	// KEMISKINAN //

	$app->get("/miskin_prov/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_miskin_prov ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Jumlah Penduduk Miskin, " . $bulan . " " . $tahun . " (Ribu Orang):Persentase Penduduk Miskin, " . $bulan . " " . $tahun . " (%):Garis Kemiskinan, " . $bulan . " " . $tahun . " (Rp/Kapita/Bulan):Indeks Kedalaman Kemiskinan, " . $bulan . " " . $tahun . ":Indeks keparahan Kemiskinan, " . $bulan . " " . $tahun . "";
			$kolom = "Wilayah:Nilai:";
			$kota = "Perkotaan";
			$desa = "Perdesaan";
			$kotadesa = "Kota + Desa";
		} else if ($lang == "en") {
			$judul = "Number of Poor People (Thousand People):Percentage of Poor People (%):Poverty Line (Rp/Capita/Month):Poverty Gap Index:Poverty Severity Index";
			$kolom = "Area:Value:";
			$kota = "Urban";
			$desa = "Rural";
			$kotadesa = "Urban  + Rural";
		}

		$sql = "SELECT '" . $kota . "' AS isi1, miskin_kota AS isi2, '' AS isi3 FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                SELECT '" . $desa . "', miskin_desa, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                SELECT '" . $kotadesa . "', miskin_kotadesa, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                SELECT '" . $kota . "', p0_kota, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS d UNION ALL
                SELECT '" . $desa . "', p0_desa, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS e UNION ALL
                SELECT '" . $kotadesa . "', p0_kotadesa, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS f UNION ALL
                SELECT '" . $kota . "', REPLACE(gk_kota, '.000', ''), '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS g UNION ALL
                SELECT '" . $desa . "', gk_desa, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS h UNION ALL
                SELECT '" . $kotadesa . "', gk_kotadesa, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS i UNION ALL
                SELECT '" . $kota . "', p1_kota, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS j UNION ALL
                SELECT '" . $desa . "', p1_desa, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS k UNION ALL
                SELECT '" . $kotadesa . "', p1_kotadesa, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS l UNION ALL
                SELECT '" . $kota . "', p2_kota, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS m UNION ALL
                SELECT '" . $desa . "', p2_desa, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS n UNION ALL
                SELECT '" . $kotadesa . "', p2_kotadesa, '' FROM (SELECT * FROM t_miskin_prov ORDER BY id DESC LIMIT 1) AS o";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/miskin_prov/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_miskin_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Angka Kemiskinan Provinsi Jawa Tengah Tahun " . $tahun;
			$kolom = "Indikator:Perkotaan:Perdesaan:Perkotaan + Perdesaan";
			$kolom1 = "Maret:September";
			$x = "Jumlah Penduduk Miskin (Ribu Orang)";
			$y = "Persentase Penduduk Miskin (%)";
			$z = "Garis Kemiskinan (Rp/Kapita/Bulan)";
			$a = "Indeks Kedalaman Kemiskinan";
			$b = "Indeks Keparahan Kemiskinan";
		} else if ($lang == "en") {
			$judul = "Poverty Rate in Jawa Tengah Province, " . $tahun;
			$kolom = "Indicator:Urban:Rural:Urban + Rural";
			$kolom1 = "March:September";
			$x = "Number of Poor People (Thousands People)";
			$y = "Percentage of Poor People (%)";
			$z = "Poverty Line (Rp/Capita/Month)";
			$a = "Poverty Gap Index";
			$b = "Poverty Severity Index";
		}

		for ($i = 3; $i <= 9; $i++) {
			if ($i == 3 || $i == 9) {
				$sql = "SELECT '" . $x . "' AS isi1, miskin_kota AS isi2, miskin_desa AS isi3, miskin_kotadesa AS isi4 FROM (SELECT * FROM t_miskin_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT '" . $y . "', p0_kota, p0_desa, p0_kotadesa FROM (SELECT * FROM t_miskin_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT '" . $z . "', REPLACE(gk_kota, '.000',''), REPLACE(gk_desa, '.000',''), REPLACE(gk_kotadesa, '.000','') FROM (SELECT * FROM t_miskin_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT '" . $a . "', p1_kota, p1_desa, p1_kotadesa FROM (SELECT * FROM t_miskin_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT '" . $b . "', p2_kota, p2_desa, p2_kotadesa FROM (SELECT * FROM t_miskin_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e";
				$stmt = $this->db->prepare($sql);
				$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
				$result = $stmt->fetchAll();
				$result1 = array("bulan" => $i);
				array_push($result1, $result);
				if ($i == 3) {
					$arr = array($result1);
				} else {
					array_push($arr, $result1);
				}
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_miskin_prov"], 200);
	});

	$app->get("/miskin_kab/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];

		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_miskin_kab WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Angka Kemiskinan Kabupaten/Kota di Provinsi Jawa Tengah, Maret " . $tahun;
			$kolom = "Nama Kab/Kota:Jumlah Penduduk Miskin (Ribu Orang):Persentase Penduduk Miskin (%):Garis Kemiskinan (Rp/Kapita/Bulan):Indeks Kedalaman Kemiskinan:Indeks Keparahan Kemiskinan";
		} else if ($lang == "en") {
			$judul = "Poverty Rate of Regency/Municipality in Jawa Tengah Province, March " . $tahun;
			$kolom = "Regency/Municipality:Number of Poor People (Thousands People):Percentage of Poor People (%):Poverty Line (Rp/Capita/Month):Poverty Gap Index:Poverty Severity Index";
		}

		$sql = "SELECT kab AS isi1, REPLACE(FORMAT(kab_miskin_jmh, 2), '.', ',') AS isi2, REPLACE(FORMAT(kab_p0, 2), '.', ',') AS isi3, REPLACE(FORMAT(kab_gk,0), ',', '.') AS isi4, 
		        REPLACE(FORMAT(kab_p1, 2), '.', ',') AS isi5, REPLACE(FORMAT(kab_p2, 2), '.', ',') AS isi6 FROM t_miskin_kab WHERE tahun=:tahun ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result, "tabel" => "t_miskin_kab"], 200);

	});

	// GINI RATIO //

	$app->get("/gini_ratio_prov/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_giniratio ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Gini Ratio Provinsi Jawa Tengah Menurut Wilayah, " . $bulan . " " . $tahun;
			$kolom = "Wilayah:Nilai:";
			$sql = "SELECT 'Perkotaan' AS isi1, gr_kota AS isi2, '' AS isi3 FROM (SELECT * FROM t_giniratio ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Perdesaan', gr_desa, '' FROM (SELECT * FROM t_giniratio ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Kota + Desa', gr_desakota, '' FROM (SELECT * FROM t_giniratio ORDER BY id DESC LIMIT 1) AS c";
		} else if ($lang == "en") {
			$judul = "Gini Ratio of Jawa Tengah Province by Area, " . $bulan . " " . $tahun;
			$kolom = "Area:Value:";
			$sql = "SELECT 'Urban' AS isi1, gr_kota AS isi2, '' AS isi3 FROM (SELECT * FROM t_giniratio ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Rural', gr_desa, '' FROM (SELECT * FROM t_giniratio ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Urban + Rural', gr_desakota, '' FROM (SELECT * FROM t_giniratio ORDER BY id DESC LIMIT 1) AS c";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();
		$item = reset($result);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/gini_ratio_prov/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_giniratio WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Gini Ratio Provinsi Jawa Tengah Menurut Wilayah, " . $tahun;
			$kolom = "Wilayah:Maret:September";
			$kota = "Perkotaan";
			$desa = "Perdesaan";
			$kotadesa = "Kota + Desa";
		} else if ($lang == "en") {
			$judul = "Gini Ratio of Jawa Tengah Province by Area, " . $tahun;
			$kolom = "Area:March:September";
			$kota = "Urban";
			$desa = "Rural";
			$kotadesa = "Urban + Rural";
		}

		for ($i = 3; $i <= 9; $i++) {
			if ($i == 3 || $i == 9) {
				$sql = "SELECT '" . $kota . "' AS isi1, gr_kota AS isi2, '' AS isi3 FROM (SELECT * FROM t_giniratio WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT '" . $desa . "', gr_desa, '' FROM (SELECT * FROM t_giniratio WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT '" . $kotadesa . "', gr_desakota, '' FROM (SELECT * FROM t_giniratio WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c";
				$stmt = $this->db->prepare($sql);
				$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
				$result = $stmt->fetchAll();
				$result1 = array("bulan" => $i);
				array_push($result1, $result);
				if ($i == 3) {
					$arr = array($result1);
				} else {
					array_push($arr, $result1);
				}
			}
		}
		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);


		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_giniratio"], 200);
	});

	$app->get("/gini_ratio_prov_series/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Gini Ratio Series Year On Year Jawa Tengah Menurut Wilayah";
			$kolom = "Tahun:Perkotaan:Perdesaan:Kota + Desa";
			$kolom1 = "Maret:September";
			$bulan = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
		} else if ($lang == "en") {
			$judul = "Series YoY of Gini Ratio by Area in Jawa Tengah Province";
			$kolom = "Year:Urban:Rural:Urban + Rural";
			$kolom1 = "March:September";
			$bulan = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
		}

		for ($i = 3; $i <= 9; $i++) {
			if ($i == 3 || $i == 9) {
				$sql = "SELECT tahun AS isi1, gr_kota AS isi2, gr_desa AS isi3, gr_desakota AS isi4 FROM t_giniratio WHERE id<>0 AND bulan=:bulan ORDER BY id";
				$stmt = $this->db->prepare($sql);
				$stmt->execute([":bulan" => $i]);
				$result = $stmt->fetchAll();
				$result1 = array("bulan" => $bulan[$i - 1]);
				array_push($result1, $result);
				if ($i == 3) {
					$arr = array($result1);
				} else {
					array_push($arr, $result1);
				}
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr], 200);
	});

	// PENGANGGURAN //

	$app->get("/tpak_prov/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_naker_prov ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Jawa Tengah " . $bulan . " " . $tahun . ":Menurut Jenis kelamin:Menurut Wilayah";
			$kolom = "Keterangan:TPAK (%):TPT (%)";
			$sql = "SELECT 'Nilai' AS isi1, tpak AS isi2, tpt AS isi3 FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Laki-laki', tpak_l, tpt_l FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Perempuan', tpak_p, tpt_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'Perkotaan', tpak_kota, tpt_kota FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS d UNION ALL
                    SELECT 'Perdesaan', tpak_desa, tpt_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS e";
		} else if ($lang == "en") {
			$judul = "Jawa Tengah " . $bulan . " " . $tahun . ":by Gender:by Area";
			$kolom = "Description:TPAK (%):TPT (%)";
			$sql = "SELECT 'Value' AS isi1, tpak AS isi2, tpt AS isi3 FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Male', tpak_l, tpt_l FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Female', tpak_p, tpt_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'Urban', tpak_kota, tpt_kota FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS d UNION ALL
                    SELECT 'Rural', tpak_desa, tpt_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS e";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/tpak_prov/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_naker_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Tingkat Partisipasi Angkatan Kerja dan Tingkat Pengangguran Terbuka Menurut jenis Kelamin dan Wilayah di Provinsi Jawa Tengah Tahun " . $tahun;
			$judul1 = "Provinsi Jawa Tengah:Menurut Jenis Kelamin:Menurut Wilayah";
			$kolom = "Keterangan:TPAK:TPT";
			$kolom1 = "Februari:Agustus";
			$nilai = "Nilai";
			$laki = "Laki-laki";
			$perempuan = "Perempuan";
			$kota = "Perkotaan";
			$desa = "Perdesaan";
		} else if ($lang == "en") {
			$judul = "Labor Force Participation Rate and Open Unemployment Rate by Gender and Area in Jawa Tengah Province, " . $tahun;
			$judul1 = "Jawa Tengah Province:by Gender:by Area";
			$kolom = "Description:TPAK:TPT";
			$kolom1 = "February:August";
			$nilai = "Value";
			$laki = "Male";
			$perempuan = "Female";
			$kota = "Urban";
			$desa = "Rural";
		}

		for ($i = 2; $i <= 8; $i++) {
			if ($i == 2 || $i == 8) {
				$sql = "SELECT '" . $nilai . "' AS isi1, tpak AS isi2, tpt AS isi3 FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT '" . $laki . "', tpak_l, tpt_l FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT '" . $perempuan . "', tpak_p, tpt_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT '" . $kota . "', tpak_kota, tpt_kota FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT '" . $desa . "', tpak_desa, tpt_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e";
				$stmt = $this->db->prepare($sql);
				$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
				$result = $stmt->fetchAll();
				$result1 = array("bulan" => $i);
				array_push($result1, $result);
				if ($i == 2) {
					$arr = array($result1);
				} else {
					array_push($arr, $result1);
				}
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "judul1" => $judul1, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_naker_prov"], 200);
	});

	$app->get("/naker_lu_jk/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_naker_prov ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];

		if (($lang == "in" || $lang == "id")) {
			if ($bulan == "Februari" && $tahun == "2025") {
				$judul = "Jumlah Pekerja Menurut Jenis kelamin, Agustus 2024 (Juta Orang)";
			} else {
				$judul = "Jumlah Pekerja Menurut Jenis kelamin, " . $bulan . " " . $tahun . " (Juta Orang)";
			}
			$kolom = "Lapangan Usaha:Laki-laki:Perempuan";
			$master = [['x'=>'Pertanian, Kehutanan, dan Perikanan'],['x'=>'Pertambangan dan Penggalian'],['x'=>'Industri Pengolahan'],['x'=>'Pengadaan Listrik dan Gas'],['x'=>'Pengadaan Air, Pengelolaan Sampah, Limbah'],['x'=>'Konstruksi'],['x'=>'Perdagangan Besar dan Eceran; Reparasi Mobil dan Sepeda Motor'],['x'=>'Transportasi dan Pergudangan'],['x'=>'Penyediaan Akomodasi dan Makan Minum'],['x'=>'Informasi dan Komunikasi'],['x'=>'Jasa Keuangan dan Asuransi'],['x'=>'Real Estat'],['x'=>'Jasa Perusahaan'],['x'=>'Administrasi Pemerintahan, Pertahanan dan Jaminan Sosial Wajib'],['x'=>'Jasa Pendidikan'],['x'=>'Jasa Kesehatan dan Kegiatan Sosial'],['x'=>'Jasa Lainnya'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			if ($bulan == "Februari" && $tahun == "2025") {
				$judul = "Number of Workers by Gender, August 2024 (Million People)";
			} else {
				$judul = "Number of Workers by Gender, " . $bulan . " " . $tahun . " (Million People)";
			}
			$kolom = "Industrial Origin:Male:Female";
			$master = [['x'=>'Agriculture, Forestry and Fisheries'],['x'=>'Mining and Quarrying'],['x'=>'Manufacturing'],['x'=>'Electricity and Gas'],['x'=>'Water Supply, Sewerage, Waste Management'],['x'=>'Construction'],['x'=>'Wholesale and Retail Trade; Repair of Motor Vehicles and Motorcycles'],['x'=>'Transportation and Storage'],['x'=>'Accommodation and Food Service Activities'],['x'=>'Information and Communication'],['x'=>'Financial and Insurance Activities'],['x'=>'Real Estate Activities'],['x'=>'Business Activities'],['x'=>'Public Administration, Defence and Compulsory Social Security'],['x'=>'Education'],['x'=>'Human Health and Social Work Activities'],['x'=>'Other Services Activities'],['x'=>'GRDP']];
		}

		// $master already assigned above

		$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, a_l AS isi2, a_p AS isi3 FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                SELECT '" . $master[1]['x'] . "', b_l, b_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                SELECT '" . $master[2]['x'] . "', c_l, c_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                SELECT '" . $master[3]['x'] . "', d_l, d_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS d UNION ALL
                SELECT '" . $master[4]['x'] . "', e_l, e_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS e UNION ALL
                SELECT '" . $master[5]['x'] . "', f_l, f_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS f UNION ALL
                SELECT '" . $master[6]['x'] . "', g_l, g_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS g UNION ALL
                SELECT '" . $master[7]['x'] . "', h_l, h_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS h UNION ALL
                SELECT '" . $master[8]['x'] . "', i_l, i_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS i UNION ALL
                SELECT '" . $master[9]['x'] . "', j_l, j_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS j UNION ALL
                SELECT '" . $master[10]['x'] . "', k_l, k_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS k UNION ALL
                SELECT '" . $master[11]['x'] . "', l_l, l_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS l UNION ALL
                SELECT '" . $master[12]['x'] . "', mn_l, mn_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS m UNION ALL
                SELECT '" . $master[13]['x'] . "', o_l, o_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS n UNION ALL
                SELECT '" . $master[14]['x'] . "', p_l, p_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS o UNION ALL
                SELECT '" . $master[15]['x'] . "', q_l, q_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS p UNION ALL
                SELECT '" . $master[16]['x'] . "', rstu_l, rstu_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS q";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/naker_lu_jk/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_naker_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Jumlah Pekerja Menurut Lapangan Usaha dan Jenis Kelamin di Provinsi Jawa Tengah Tahun " . $tahun . " (Juta Orang)";
			$kolom = "Lapangan Usaha:Laki-laki:Perempuan";
			$kolom1 = "Februari:Agustus";
			if ($tahun < 2018)
				$sqlMaster = "SELECT nama AS x FROM m_lapus_old ORDER BY id";
			if ($tahun > 2017)
				$master = [['x'=>'Pertanian, Kehutanan, dan Perikanan'],['x'=>'Pertambangan dan Penggalian'],['x'=>'Industri Pengolahan'],['x'=>'Pengadaan Listrik dan Gas'],['x'=>'Pengadaan Air, Pengelolaan Sampah, Limbah'],['x'=>'Konstruksi'],['x'=>'Perdagangan Besar dan Eceran; Reparasi Mobil dan Sepeda Motor'],['x'=>'Transportasi dan Pergudangan'],['x'=>'Penyediaan Akomodasi dan Makan Minum'],['x'=>'Informasi dan Komunikasi'],['x'=>'Jasa Keuangan dan Asuransi'],['x'=>'Real Estat'],['x'=>'Jasa Perusahaan'],['x'=>'Administrasi Pemerintahan, Pertahanan dan Jaminan Sosial Wajib'],['x'=>'Jasa Pendidikan'],['x'=>'Jasa Kesehatan dan Kegiatan Sosial'],['x'=>'Jasa Lainnya'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			$judul = "Number of Workers by Industrial Origin and Gender in Jawa Tengah Province, " . $tahun . " (Million People)";
			$kolom = "Industrial Origin:Male:Female";
			$kolom1 = "February:August";
			if ($tahun < 2018)
				$sqlMaster = "SELECT name AS x FROM m_lapus_old ORDER BY id";
			if ($tahun > 2017)
				$master = [['x'=>'Agriculture, Forestry and Fisheries'],['x'=>'Mining and Quarrying'],['x'=>'Manufacturing'],['x'=>'Electricity and Gas'],['x'=>'Water Supply, Sewerage, Waste Management'],['x'=>'Construction'],['x'=>'Wholesale and Retail Trade; Repair of Motor Vehicles and Motorcycles'],['x'=>'Transportation and Storage'],['x'=>'Accommodation and Food Service Activities'],['x'=>'Information and Communication'],['x'=>'Financial and Insurance Activities'],['x'=>'Real Estate Activities'],['x'=>'Business Activities'],['x'=>'Public Administration, Defence and Compulsory Social Security'],['x'=>'Education'],['x'=>'Human Health and Social Work Activities'],['x'=>'Other Services Activities'],['x'=>'GRDP']];
		}

		$stmtMaster = $this->db->prepare($sqlMaster);
		$stmtMaster->execute();
		$master = $stmtMaster->fetchAll();

		for ($i = 2; $i <= 8; $i++) {
			if ($i == 2 || $i == 8) {
				if ($tahun < 2018) {
					$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, pertanian_l AS isi2, pertanian_p AS isi3 FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                            SELECT '" . $master[1]['x'] . "', pertambangan_l, pertambangan_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                            SELECT '" . $master[2]['x'] . "', industri_l, industri_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                            SELECT '" . $master[3]['x'] . "', listrik_l, listrik_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                            SELECT '" . $master[4]['x'] . "', konstruksi_l, konstruksi_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                            SELECT '" . $master[5]['x'] . "', perdagangan_l, perdagangan_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                            SELECT '" . $master[6]['x'] . "', angkutan_l, angkutan_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                            SELECT '" . $master[7]['x'] . "', keuangan_l, keuangan_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                            SELECT '" . $master[8]['x'] . "', jasa_l, jasa_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS q";
				} else {
					$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, a_l AS isi2, a_p AS isi3 FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                            SELECT '" . $master[1]['x'] . "', b_l, b_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                            SELECT '" . $master[2]['x'] . "', c_l, c_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                            SELECT '" . $master[3]['x'] . "', d_l, d_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                            SELECT '" . $master[4]['x'] . "', e_l, e_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                            SELECT '" . $master[5]['x'] . "', f_l, f_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                            SELECT '" . $master[6]['x'] . "', g_l, g_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                            SELECT '" . $master[7]['x'] . "', h_l, h_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                            SELECT '" . $master[8]['x'] . "', i_l, i_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i UNION ALL
                            SELECT '" . $master[9]['x'] . "', j_l, j_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS j UNION ALL
                            SELECT '" . $master[10]['x'] . "', k_l, k_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS k UNION ALL
                            SELECT '" . $master[11]['x'] . "', l_l, l_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS l UNION ALL
                            SELECT '" . $master[12]['x'] . "', mn_l, mn_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS m UNION ALL
                            SELECT '" . $master[13]['x'] . "', o_l, o_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS n UNION ALL
                            SELECT '" . $master[14]['x'] . "', p_l, p_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS o UNION ALL
                            SELECT '" . $master[15]['x'] . "', q_l, q_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS p UNION ALL
                            SELECT '" . $master[16]['x'] . "', rstu_l, rstu_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS q";
				}

				$stmt = $this->db->prepare($sql);
				$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
				$result = $stmt->fetchAll();
				$result1 = array("bulan" => $i);
				array_push($result1, $result);
				if ($i == 2) {
					$arr = array($result1);
				} else {
					array_push($arr, $result1);
				}
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_naker_prov"], 200);
	});

	$app->get("/naker_lu_wilayah/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_naker_prov ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];
		if (($lang == "in" || $lang == "id")) {
			if ($bulan == "Februari" && $tahun == "2025") {
				$judul = "Jumlah Pekerja Menurut Wilayah, Agustus 2024 (Juta Orang)";
			} else {
				$judul = "Jumlah Pekerja Menurut Wilayah, " . $bulan . " " . $tahun . " (Juta Orang)";
			}
			$kolom = "Lapangan Usaha:Perkotaan:Perdesaan";
			$master = [['x'=>'Pertanian, Kehutanan, dan Perikanan'],['x'=>'Pertambangan dan Penggalian'],['x'=>'Industri Pengolahan'],['x'=>'Pengadaan Listrik dan Gas'],['x'=>'Pengadaan Air, Pengelolaan Sampah, Limbah'],['x'=>'Konstruksi'],['x'=>'Perdagangan Besar dan Eceran; Reparasi Mobil dan Sepeda Motor'],['x'=>'Transportasi dan Pergudangan'],['x'=>'Penyediaan Akomodasi dan Makan Minum'],['x'=>'Informasi dan Komunikasi'],['x'=>'Jasa Keuangan dan Asuransi'],['x'=>'Real Estat'],['x'=>'Jasa Perusahaan'],['x'=>'Administrasi Pemerintahan, Pertahanan dan Jaminan Sosial Wajib'],['x'=>'Jasa Pendidikan'],['x'=>'Jasa Kesehatan dan Kegiatan Sosial'],['x'=>'Jasa Lainnya'],['x'=>'PDRB']];
		} else if ($lang == "en") {
			if ($bulan == "Februari" && $tahun == "2025") {
				$judul = "Number of Workers by Area, August 2024 (Million People)";
			} else {
				$judul = "Number of Workers by Area, " . $bulan . " " . $tahun . " (Million People)";
			}
			$kolom = "Industrial Origin:Urban:Rural";
			$master = [['x'=>'Agriculture, Forestry and Fisheries'],['x'=>'Mining and Quarrying'],['x'=>'Manufacturing'],['x'=>'Electricity and Gas'],['x'=>'Water Supply, Sewerage, Waste Management'],['x'=>'Construction'],['x'=>'Wholesale and Retail Trade; Repair of Motor Vehicles and Motorcycles'],['x'=>'Transportation and Storage'],['x'=>'Accommodation and Food Service Activities'],['x'=>'Information and Communication'],['x'=>'Financial and Insurance Activities'],['x'=>'Real Estate Activities'],['x'=>'Business Activities'],['x'=>'Public Administration, Defence and Compulsory Social Security'],['x'=>'Education'],['x'=>'Human Health and Social Work Activities'],['x'=>'Other Services Activities'],['x'=>'GRDP']];
		}
		// $master already assigned above

		$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, a_kota AS isi2, a_desa AS isi3 FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                SELECT '" . $master[1]['x'] . "', b_kota, b_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                SELECT '" . $master[2]['x'] . "', c_kota, c_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                SELECT '" . $master[3]['x'] . "', d_kota, d_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS d UNION ALL
                SELECT '" . $master[4]['x'] . "', e_kota, e_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS e UNION ALL
                SELECT '" . $master[5]['x'] . "', f_kota, f_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS f UNION ALL
                SELECT '" . $master[6]['x'] . "', g_kota, g_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS g UNION ALL
                SELECT '" . $master[7]['x'] . "', h_kota, h_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS h UNION ALL
                SELECT '" . $master[8]['x'] . "', i_kota, i_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS i UNION ALL
                SELECT '" . $master[9]['x'] . "', j_kota, j_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS j UNION ALL
                SELECT '" . $master[10]['x'] . "', k_kota, k_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS k UNION ALL
                SELECT '" . $master[11]['x'] . "', l_kota, l_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS l UNION ALL
                SELECT '" . $master[12]['x'] . "', mn_kota, mn_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS m UNION ALL
                SELECT '" . $master[13]['x'] . "', o_kota, o_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS n UNION ALL
                SELECT '" . $master[14]['x'] . "', p_kota, p_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS o UNION ALL
                SELECT '" . $master[15]['x'] . "', q_kota, q_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS p UNION ALL
                SELECT '" . $master[16]['x'] . "', rstu_kota, rstu_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS q";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/naker_lu_wilayah/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_naker_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Jumlah Pekerja Menurut Lapangan Usaha dan Wilayah di Provinsi Jawa Tengah, " . $tahun . " (Juta Orang)";
			$kolom = "Lapangan Usaha:Perkotaan:Perdesaan";
			$kolom1 = "Februari:Agustus";
			if ($tahun < 2018)
				$sqlMaster = "SELECT nama AS x FROM m_lapus_old ORDER BY id";
			if ($tahun > 2017)
				$sqlMaster = "SELECT nama AS x FROM m_pdrb_lapus ORDER BY id";
		} else if ($lang == "en") {
			$judul = "Number of Workers by Industrial Origin and by Area in Jawa Tengah Province, " . $tahun . " (Million People)";
			$kolom = "Industrial Origin:Urban:Rural";
			$kolom1 = "February:August";
			if ($tahun < 2018)
				$sqlMaster = "SELECT name AS x FROM m_lapus_old ORDER BY id";
			if ($tahun > 2017)
				$master = [['x'=>'Agriculture, Forestry and Fisheries'],['x'=>'Mining and Quarrying'],['x'=>'Manufacturing'],['x'=>'Electricity and Gas'],['x'=>'Water Supply, Sewerage, Waste Management'],['x'=>'Construction'],['x'=>'Wholesale and Retail Trade; Repair of Motor Vehicles and Motorcycles'],['x'=>'Transportation and Storage'],['x'=>'Accommodation and Food Service Activities'],['x'=>'Information and Communication'],['x'=>'Financial and Insurance Activities'],['x'=>'Real Estate Activities'],['x'=>'Business Activities'],['x'=>'Public Administration, Defence and Compulsory Social Security'],['x'=>'Education'],['x'=>'Human Health and Social Work Activities'],['x'=>'Other Services Activities'],['x'=>'GRDP']];
		}
		$stmtMaster = $this->db->prepare($sqlMaster);
		$stmtMaster->execute();
		$master = $stmtMaster->fetchAll();

		for ($i = 2; $i <= 8; $i++) {
			if ($i == 2 || $i == 8) {
				if ($tahun < 2018) {
					$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, pertanian_kota AS isi2, pertanian_desa AS isi3 FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                            SELECT '" . $master[1]['x'] . "', pertambangan_kota, pertambangan_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                            SELECT '" . $master[2]['x'] . "', industri_kota, industri_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                            SELECT '" . $master[3]['x'] . "', listrik_kota, listrik_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                            SELECT '" . $master[4]['x'] . "', konstruksi_kota, konstruksi_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                            SELECT '" . $master[5]['x'] . "', perdagangan_kota, perdagangan_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                            SELECT '" . $master[6]['x'] . "', angkutan_kota, angkutan_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                            SELECT '" . $master[7]['x'] . "', keuangan_kota, keuangan_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                            SELECT '" . $master[8]['x'] . "', jasa_kota, jasa_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS q";
				} else {
					$sql = "SELECT '" . $master[0]['x'] . "' AS isi1, a_kota AS isi2, a_desa AS isi3 FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                            SELECT '" . $master[1]['x'] . "', b_kota, b_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                            SELECT '" . $master[2]['x'] . "', c_kota, c_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                            SELECT '" . $master[3]['x'] . "', d_kota, d_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                            SELECT '" . $master[4]['x'] . "', e_kota, e_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e UNION ALL
                            SELECT '" . $master[5]['x'] . "', f_kota, f_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS f UNION ALL
                            SELECT '" . $master[6]['x'] . "', g_kota, g_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS g UNION ALL
                            SELECT '" . $master[7]['x'] . "', h_kota, h_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS h UNION ALL
                            SELECT '" . $master[8]['x'] . "', i_kota, i_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS i UNION ALL
                            SELECT '" . $master[9]['x'] . "', j_kota, j_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS j UNION ALL
                            SELECT '" . $master[10]['x'] . "', k_kota, k_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS k UNION ALL
                            SELECT '" . $master[11]['x'] . "', l_kota, l_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS l UNION ALL
                            SELECT '" . $master[12]['x'] . "', mn_kota, mn_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS m UNION ALL
                            SELECT '" . $master[13]['x'] . "', o_kota, o_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS n UNION ALL
                            SELECT '" . $master[14]['x'] . "', p_kota, p_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS o UNION ALL
                            SELECT '" . $master[15]['x'] . "', q_kota, q_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS p UNION ALL
                            SELECT '" . $master[16]['x'] . "', rstu_kota, rstu_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS q";
				}

				$stmt = $this->db->prepare($sql);
				$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
				$result = $stmt->fetchAll();
				$result1 = array("bulan" => $i);
				array_push($result1, $result);
				if ($i == 2) {
					$arr = array($result1);
				} else {
					array_push($arr, $result1);
				}
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_naker_prov"], 200);
	});

	$app->get("/naker_formal_prov/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_naker_prov ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];
		if (($lang == "in" || $lang == "id")) {
			if ($bulan == "Februari" && $tahun == "2025") {
				$judul = "Pekerja Menurut Jenis kelamin, Agustus 2024 (Juta Orang):Pekerja Menurut Wilayah, Agustus 2024 (Juta Orang)";
			} else {
				$judul = "Pekerja Menurut Jenis kelamin, " . $bulan . " " . $tahun . " (Juta Orang):Pekerja Menurut Wilayah, " . $bulan . " " . $tahun . " (Juta Orang)";
			}
			$kolom = "Keterangan:Formal:Informal";
			$sql = "SELECT 'Laki-laki' AS isi1, formal_l AS isi2, informal_l AS isi3 FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Perempuan', formal_p, informal_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Perkotaan', formal_kota, informal_kota FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'Perdesaan', formal_desa, informal_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS d";
		} else if ($lang == "en") {
			if ($bulan == "Februari" && $tahun == "2025") {
				$judul = "Workers by Gender, August 2024 (Million People):Workers by Area, August 2024 (Million People)";
			} else {
				$judul = "Workers by Gender, " . $bulan . " " . $tahun . " (Million People):Workers by Area, " . $bulan . " " . $tahun . " (Million People)";
			}
			$kolom = "Description:Formal:Informal";
			$sql = "SELECT 'Male' AS isi1, formal_l AS isi2, informal_l AS isi3 FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Female', formal_p, informal_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Urban', formal_kota, informal_kota FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'Rural', formal_desa, informal_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS d";
		}
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/naker_formal_prov/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_naker_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Jumlah Pekerja Formal dan Informal Menurut jenis Kelamin dan Wilayah di Provinsi Jawa Tengah Tahun " . $tahun . " (Juta Orang)";
			$judul1 = "Menurut Jenis Kelamin:Menurut Wilayah";
			$kolom = "Keterangan:Formal:Informal";
			$kolom1 = "Februari:Agustus";
			$laki = "Laki-laki";
			$perempuan = "Perempuan";
			$kota = "Perkotaan";
			$desa = "Perdesaan";
		} else if ($lang == "en") {
			$judul = "Number of Formal and Informal Workers by Gender and by Area in Jawa Tengah Province, " . $tahun . " (Million People)";
			$judul1 = "by Gender:by Area";
			$kolom = "Description:Formal:Informal";
			$kolom1 = "February:August";
			$laki = "Male";
			$perempuan = "Female";
			$kota = "Urban";
			$desa = "Rural";
		}

		for ($i = 2; $i <= 8; $i++) {
			if ($i == 2 || $i == 8) {
				$sql = "SELECT '" . $laki . "' AS isi1, formal_l AS isi2, informal_l As isi3 FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT '" . $perempuan . "', formal_p, informal_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT '" . $kota . "', formal_kota, informal_kota FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT '" . $desa . "', formal_desa, informal_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d";
				$stmt = $this->db->prepare($sql);
				$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
				$result = $stmt->fetchAll();
				$result1 = array("bulan" => $i);
				array_push($result1, $result);
				if ($i == 2) {
					$arr = array($result1);
				} else {
					array_push($arr, $result1);
				}
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "judul1" => $judul1, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_naker_prov"], 200);
	});

	$app->get("/naker_pendidikan_prov/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_naker_prov ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];
		if (($lang == "in" || $lang == "id")) {
			if ($bulan == "Februari" && $tahun == "2025") {
				$judul = "Pekerja Menurut Jenis Kelamin, Agustus 2024 (Juta Orang):Pekerja Menurut Wilayah, Agustus 2024 (Juta Orang)";
			} else {
				$judul = "Pekerja Menurut Jenis Kelamin, " . $bulan . " " . $tahun . " (Juta Orang):Pekerja Menurut Wilayah, " . $bulan . " " . $tahun . " (Juta Orang)";
			}
			$kolom = "Pendidikan:Laki-lakixKota:PerempuanxDesa";
			$sd = "SD ke Bawah";
			$smp = "SMP";
			$sma = "SMA";
			$smk = "SMK";
			$d = "Diploma I/II/III";
			$s = "S1/S2/S3";
		} else if ($lang == "en") {
			if ($bulan == "Februari" && $tahun == "2025") {
				$judul = "Workers by Gender, August 2024 (Million People):Workers by Area, August 2024 (Million People)";
			} else {
				$judul = "Workers by Gender, " . $bulan . " " . $tahun . " (Million People):Workers by Area, " . $bulan . " " . $tahun . " (Million People)";
			}
			$kolom = "Education:MalexUrban:FemalexRural";
			$sd = "Primary School or Below";
			$smp = "Junior High School";
			$sma = "Senior High School";
			$smk = "Vacational Senior High School";
			$d = "Diploma I/II/III";
			$s = "S1/S2/S3";
		}


		$sql = "SELECT '" . $sd . "' AS isi1, sd_l AS isi2, sd_p AS isi3 FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                SELECT '" . $smp . "', smp_l, smp_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                SELECT '" . $sma . "', sma_l, sma_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                SELECT '" . $smk . "', smk_l, smk_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS d UNION ALL
                SELECT '" . $d . "', diploma_l, diploma_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS e UNION ALL
                SELECT '" . $s . "', sarjana_l, sarjana_p FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS f UNION ALL
                SELECT '" . $sd . "', sd_kota, sd_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS g UNION ALL
                SELECT '" . $smp . "', smp_kota, smp_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS h UNION ALL
                SELECT '" . $sma . "', sma_kota, sma_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS i UNION ALL
                SELECT '" . $smk . "', smk_kota, smk_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS j UNION ALL
                SELECT '" . $d . "', diploma_kota, diploma_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS k UNION ALL
                SELECT '" . $s . "', sarjana_kota, sarjana_desa FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS l";

		$stmt1 = $this->db->prepare($sql);
		$stmt1->execute();
		$result = $stmt1->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/naker_pendidikan_prov/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_naker_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Jumlah Pekerja Menurut Pendidikan Tertinggi yang Ditamatkan, Jenis Kelamin, dan Wilayah di Provinsi Jawa Tengah, " . $tahun . " <br>(Juta Orang)";
			$judul1 = "Menurut Jenis Kelamin:Menurut Wilayah";
			$kolom = "Keterangan:SD Ke Bawah:SMP:SMA:SMK:Diploma I/DII/DIII:S1/S2/S3";
			$kolom1 = "Februari:Agustus";
			$laki = "Laki-laki";
			$perempuan = "Perempuan";
			$kota = "Perkotaan";
			$desa = "Perdesaan";
		} else if ($lang == "en") {
			$judul = "Number of Workers by Education, Gender, and Area in Jawa Tengah Province, " . $tahun . " (Million People)";
			$judul1 = "by Gender:by Area";
			$kolom = "Description:Primary School or Below:Junior High School:Senior High School:Vacational Senior High School:Diploma I/DII/DIII:S1/S2/S3";
			$kolom1 = "February:August";
			$laki = "Male";
			$perempuan = "Female";
			$kota = "Urban";
			$desa = "Rural";
		}

		for ($i = 2; $i <= 8; $i++) {
			if ($i == 2 || $i == 8) {
				$sql = "SELECT '" . $laki . "' AS isi1, sd_l AS isi2, smp_l As isi3, sma_l AS isi4, smk_l AS isi5, diploma_l AS isi6, sarjana_l AS isi7 FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT '" . $perempuan . "', sd_p, smp_p, sma_p, smk_p, diploma_p, sarjana_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT '" . $kota . "', sd_kota, smp_kota, sma_kota, smk_kota, diploma_kota, sarjana_kota FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT '" . $desa . "', sd_desa, smp_desa, sma_desa, smk_desa, diploma_desa, sarjana_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d";
				$stmt = $this->db->prepare($sql);
				$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
				$result = $stmt->fetchAll();
				$result1 = array("bulan" => $i);
				array_push($result1, $result);
				if ($i == 2) {
					$arr = array($result1);
				} else {
					array_push($arr, $result1);
				}
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "judul1" => $judul1, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $arr, "tabel" => "t_naker_prov"], 200);
	});

	$app->get("/naker_setengah_prov/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun, des_bulan FROM t_naker_prov ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		$bulan = $result['des_bulan'];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Setengah Pengangguran Provinsi Jawa Tengah, " . $bulan . " " . $tahun . ":Menurut Jenis kelamin:Menurut Wilayah";
			$kolom = "Keterangan:Nilai (Juta Orang):";
			$sql = "SELECT 'Jumlah' AS isi1, setengah AS isi2, '' AS isi3 FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Laki-laki', setengah_l, '' FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Perempuan', setengah_p, '' FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'Perkotaan', setengah_kota, '' FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS d UNION ALL
                    SELECT 'Perdesaan', setengah_desa, '' FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS e";
		} else if ($lang == "en") {
			$judul = "Uderemployment of Jawa Tengah Province, " . $bulan . " " . $tahun . ":by Gender:by Area";
			$kolom = "Description:Value (Million People):";
			$sql = "SELECT 'Total' AS isi1, setengah AS isi2, '' AS isi3 FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Male', setengah_l, '' FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Female', setengah_p, '' FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'Urban', setengah_kota, '' FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS d UNION ALL
                    SELECT 'Rural', setengah_desa, '' FROM (SELECT * FROM t_naker_prov ORDER BY id DESC LIMIT 1) AS e";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "bulan" => $bulan, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/naker_setengah_prov/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_naker_prov WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Jumlah Setengah Penganggur Menurut Jenis Kelamin dan Wilayah di Provinsi Jawa Tengah, " . $tahun . " (Juta Orang)";
			$judul1 = "Provinsi Jawa Tengah:Menurut Jenis Kelamin:Menurut Wilayah";
			$kolom = "Keterangan:Februari:Agustus";
			$nilai = "Jumlah";
			$laki = "Laki-laki";
			$perempuan = "Perempuan";
			$kota = "Perkotaan";
			$desa = "Perdesaan";
		} else if ($lang == "en") {
			$judul = "Number of Underemployment by Gender and Area in Jawa Tengah Province, " . $tahun . " (Million People)";
			$judul1 = "Jawa Tengah Province:by Gender:by Area";
			$kolom = "Description:February:August";
			$nilai = "Total";
			$laki = "Male";
			$perempuan = "Female";
			$kota = "Urban";
			$desa = "Rural";
		}

		for ($i = 2; $i <= 8; $i++) {
			if ($i == 2 || $i == 8) {
				$sql = "SELECT '" . $nilai . "' AS isi1, setengah AS isi2 FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS a UNION ALL
                        SELECT '" . $laki . "', setengah_l FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS b UNION ALL
                        SELECT '" . $perempuan . "', setengah_p FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS c UNION ALL
                        SELECT '" . $kota . "', setengah_kota FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS d UNION ALL
                        SELECT '" . $desa . "', setengah_desa FROM (SELECT * FROM t_naker_prov WHERE tahun=:tahun AND id<>0 AND bulan=:bulan ORDER BY id) AS e";
				$stmt = $this->db->prepare($sql);
				$stmt->execute([":tahun" => $tahun, ":bulan" => $i]);
				$result = $stmt->fetchAll();
				$result1 = array("bulan" => $i);
				array_push($result1, $result);
				if ($i == 2) {
					$arr = array($result1);
				} else {
					array_push($arr, $result1);
				}
			}
		}

		$arr = array_map(function ($arr) {
			return array('bulan' => $arr['bulan'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "judul1" => $judul1, "tahun" => $tahun, "kolom" => $kolom, "data" => $arr, "tabel" => "t_naker_prov"], 200);
	});

	$app->get("/tpak_kab/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM t_naker_kab WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Tingkat Partisipasi Angkatan Kerja dan Tingkat Pengangguran Terbuka Kabupaten/Kota di Provinsi Jawa Tengah, Agustus " . $tahun;
			$kolom = "Kabupaten/Kota:TPAK:TPT";
			$kolom1 = "Laki-laki:Perempuan:Laki-laki + Perempuan";
		} else if ($lang == "en") {
			$judul = "Labor Force Participation Rate and Open Unemployment Rate of Regency/Municipality in Jawa Tengah Province, August " . $tahun;
			$kolom = "Regency/Municipality:Labor Force Participation Rate:Open Unemployment Rate";
			$kolom1 = "Male:Female:Male + Female";
		}

		$sql = "SELECT nama_kab AS isi1, tpak_l AS isi2, tpak_p AS isi3, tpak AS isi4, tpt_l AS isi5, tpt_p AS isi6, tpt AS isi7 FROM t_naker_kab WHERE tahun=:tahun ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $result, "tabel" => "t_naker_kab"], 200);
	});

	// IPM //

	$app->get("/ipm_prov_series/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Indeks Pembangunan Manusia\nProvinsi Jawa Tengah";
			$kolom = "Tahun:IPM:Peningkatan (poin)";
		} else if ($lang == "en") {
			$judul = "Human Development Index\nJawa Tengah Province";
			$kolom = "Year:HDI:Improvement (point)";
		}

		$sql = "SELECT tahun AS isi1, ipm AS isi2, ipm_growth AS isi3 FROM t_ipm_prov WHERE id<>0 ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ipm_komponen_prov/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun FROM t_ipm_prov ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Komponen Indeks Pembangunan Manusia\nProvinsi Jawa Tengah, " . $tahun;
			$kolom = "Komponen:Nilai:";
			$sql = "SELECT 'Umur Harapan Hidup (UHH) (Tahun)' AS isi1, ahh AS isi2, '' AS isi3 FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Harapan Lama Sekolah (HLS) (Tahun)', hls, '' FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Rata-rata Lama Sekolah (RLS) (Tahun)', rls, '' FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'Purchasing Power Parity (PPP) (Ribu Rupiah)', ppp, '' FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS d";
		} else if ($lang == "en") {
			$judul = "Human Development Index Components\nJawa Tengah Province, " . $tahun;
			$kolom = "Components:Value:";
			$sql = "SELECT 'Life Expectancy Rate (Years)' AS isi1, ahh AS isi2, '' AS isi3 FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Expected Years of Schooling (Years)', hls, '' FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Mean Years of Schooling (Years)', rls, '' FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'Purchasing Power Parity (PPP) (Thousand Rupiah)', ppp, '' FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS d";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ipm_komponen_prov_series/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Komponen IPM Provinsi Jawa Tengah dan Pertumbuhannya";
			$kolom = "Tahun:Umur harapan Hidup (Tahun):Harapan Lama Sekolah (Tahun):Rata-Rata Lama Sekolah (Tahun):Purchasing Power Parity (Ribu Rupiah)";
			//    	    $kolom1 = "Nilai:Pertumbuhan (%)";
		} else if ($lang == "en") {
			$judul = "HDI Components of Jawa Tengah Province and its Growth";
			$kolom = "Year:Life Expectancy Rate (Year):Expected Years of Schooling (Year):Mean Years of Schooling (Year):Purchasing Power Parity (Thousands Rupiah)";
			//    	    $kolom1 = "Value:Growth (%)";
		}

		//        $sql = "SELECT tahun AS isi1, ahh AS isi2, ahh_growth AS isi3, hls AS isi4, hls_growth AS isi5, rls AS isi6, rls_growth AS isi7, ppp AS isi8, ppp_growth AS isi9 FROM t_ipm_prov ORDER BY id DESC";
		$sql = "SELECT tahun AS isi1, ahh AS isi2, hls AS isi3, rls AS isi4, ppp AS isi5 FROM t_ipm_prov ORDER BY id DESC";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "judul" => $judul, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $result], 200);
	});

	$app->get("/ipm_perbandingan_prov/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun FROM t_ipm_prov ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Perbandingan IPM Provinsi Jawa Tengah dengan IPM Nasional Tahun " . $tahun;
			$kolom = "Keterangan:Jateng:Nasional";
			$sql = "SELECT 'AHH (Tahun)' AS isi1, ahh AS isi2, ahh_nas AS isi3 FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'HLS (Tahun)', hls, hls_nas FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'RLS (Tahun)', rls, rls_nas FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'PPP (Ribu Rupiah)', ppp, ppp_nas FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS d UNION ALL
                    SELECT 'IPM', ipm, ipm_nas FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS e UNION ALL
                    SELECT 'Peningkatan IPM terhadap tahun sebelumnya (poin)', ipm_growth, ipm_nas_growth FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS f UNION ALL
                    SELECT 'Capaian Pembangunan Manusia', capaian_jateng, capaian_nas FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS h";
		} else if ($lang == "en") {
			$judul = "Comparison of Jawa Tengah and National HDI, " . $tahun;
			$kolom = "Description:Jateng:National";
			$sql = "SELECT 'Life Expectancy Rate (Year)' AS isi1, ahh AS isi2, ahh_nas AS isi3 FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS a UNION ALL
                    SELECT 'Expected Years of Schooling (Year)', hls, hls_nas FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS b UNION ALL
                    SELECT 'Mean Years of Schooling (Year)', rls, rls_nas FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS c UNION ALL
                    SELECT 'PPP (Thousands Rupiah)', ppp, ppp_nas FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS d UNION ALL
                    SELECT 'HDI', ipm, ipm_nas FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS e UNION ALL
                    SELECT 'Growth of HDI to previous year (point)', ipm_growth, ipm_nas_growth FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS f UNION ALL
                    SELECT 'Human Development Achievements', capaian_jateng, capaian_nas FROM (SELECT * FROM t_ipm_prov ORDER BY id DESC LIMIT 1) AS h";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ipm_kab/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_ipm_kab WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Indeks Pembangunan Manusia Kabupaten/Kota di Provinsi Jawa Tengah, " . $tahun;
			$kolom = "Kabupaten/Kota:IPM:Peningkatan (poin)";
		} else if ($lang == "en") {
			$judul = "Human Development Index of Regency/Municipality in Jawa Tengah Province, " . $tahun;
			$kolom = "Regency/Municipality:HDI:Improvement (point)";
		}

		$sql = "SELECT nama_kab AS isi1, ipm AS isi2, ipm_growth AS isi3 FROM t_ipm_kab WHERE tahun=:tahun ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result, "tabel" => "t_ipm_kab"], 200);
	});

	$app->get("/ipm_komponen_kab/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM t_ipm_kab WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2010)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Komponen IPM Kabupaten/Kota di Provinsi Jawa Tengah, Agustus " . $tahun;
			$kolom = "Kabupaten/Kota:Umur harapan Hidup (Tahun):Harapan Lama Sekolah (Tahun):Rata-Rata Lama Sekolah (Tahun):Purchasing Power Parity (Ribu Rupiah)";
			//    	    $kolom1 = "Nilai:Pertumbuhan";
		} else if ($lang == "en") {
			$judul = "Components of Regency/Municipality HDI in Jawa Tengah Province, August " . $tahun;
			$kolom = "Regency/Municipality:Life Expectancy Rate (Year):Expected Years of Schooling (Year):Mean Years of Schooling (Year):Purchasing Power Parity (Thousands Rupiah)";
			//    	    $kolom1 = "Value:Growth";
		}

		//        $sql = "SELECT nama_kab AS isi1, ahh AS isi2, ahh_growth AS isi3, hls AS isi4, hls_growth AS isi5, rls AS isi6, rls_growth AS isi7, ppp AS isi8, ppp_growth AS isi9 FROM t_ipm_kab WHERE tahun=:tahun ORDER BY id";
		$sql = "SELECT nama_kab AS isi1, ahh AS isi2, hls AS isi3, rls AS isi4, ppp AS isi5 FROM t_ipm_kab WHERE tahun=:tahun ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $result, "tabel" => "t_ipm_kab"], 200);
	});

	$app->get("/ipm_status/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT tahun FROM t_ipm_kab ORDER BY id DESC LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetch();
		$tahun = $result['tahun'];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Status Pembangunan Manusia Kabupaten/Kota di Provinsi Jawa Tengah, " . $tahun;
			$kolom = "Kabupaten/Kota:Status:";
		} else if ($lang == "en") {
			$judul = "Human Development Status of Regency/Municipality in Jawa Tengah Province, " . $tahun;
			$kolom = "Regency/Municipality:Status:";
		}

		$sql = "SELECT nama_kab AS isi1, status AS isi2, '' AS isi3 FROM t_ipm_kab WHERE tahun=:tahun ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/ipm_status_series/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$sql = "SELECT DISTINCT tahun FROM t_ipm_kab ORDER BY tahun";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$resultTemp = $stmt->fetchAll();

		$y = count($resultTemp);
		if (($lang == "in" || $lang == "id")) {
			$judul = "Series Status Pembangunan Manusia Kabupaten/Kota di Provinsi Jawa Tengah";
			$kolom = "Kabupaten/Kota:";
		} else if ($lang == "en") {
			$judul = "Series of Human Development Status of Regency/Municipality in Jawa Tengah Province";
			$kolom = "Regency/Municipality:";
		}
		for ($x = 0; $x < $y; $x++) {
			$kolom = $kolom . $resultTemp[$x]['tahun'];
			if ($x != $y - 1)
				$kolom = $kolom . ":";
		}


		for ($i = 0; $i < $y; $i++) {
			$tahun = $resultTemp[$i]['tahun'];
			$sql = "SELECT nama_kab AS isi1, status AS isi2 FROM t_ipm_kab WHERE tahun=:tahun ORDER BY id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tahun" => $tahun]);
			$result = $stmt->fetchAll();
			$result1 = array("tahun" => $tahun);
			array_push($result1, $result);
			if ($i == 0) {
				$arr = array($result1);
			} else {
				array_push($arr, $result1);
			}
		}

		$arr = array_map(function ($arr) {
			return array('tahun' => $arr['tahun'], 'data' => $arr['0']);
		}, $arr);

		return $response->withJson(["status" => "success", "judul" => $judul, "kolom" => $kolom, "data" => $arr, "tabel" => "t_ipm_kab"], 200);
	});

	// SKD

	$app->get("/skd_prov/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM ots_skd WHERE tahun=:tahun AND trw LIKE 'triwulan%' ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2022)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Hasil Survey Kebutuhan Data BPS Provinsi Jawa Tengah, " . $tahun . " \n(Metode Kemenpan)";
			$kolom = "Triwulan:Indeks Persepsi Kualitas Pelayanan - IPKP:Indeks Persepsi Anti Korupsi - IPAK";
		} else if ($lang == "en") {
			$judul = "Customer Satisfaction Indeks Jawa Tengah Province, " . $tahun;
			$kolom = "Quarter:Target:Achievements";
		}
		$sql = "SELECT trw AS isi1, ipkp AS isi2, ipak AS isi3 FROM ots_skd WHERE tahun=:tahun AND kab LIKE '3300%' AND trw LIKE 'triwulan%' ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result, "tabel" => "ots_skd"], 200);
	});

	$app->get("/skd_kab/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM ots_skd WHERE tahun=:tahun AND trw LIKE 'triwulan%' ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Hasil Survey Kebutuhan Data BPS Kabupaten/Kota di Provinsi Jawa Tengah, " . $tahun;
			$kolom = "Kabupaten/Kota:Triwulan I:Triwulan II:Triwulan III:Triwulan IV";
			$kolom1 = "Indeks Persepsi Kualitas Pelayanan - IPKP:Indeks Persepsi Anti Korupsi - IPAK";
		} else if ($lang == "en") {
			$judul = "Result of Data Need Survey for BPS of Regency/Municipality in Jawa Tengah Province, " . $tahun;
			$kolom = "Regency/Municipality:Q-I:Q-II:Q-III:Q-IV";
			$kolom1 = "Service Quality Perception Index - IPKP:Anti Corruption Perception Index - IPAK";
		}

		$sql = "SELECT a.kab AS isi1, CASE WHEN a.ipkp = '0' THEN '-' ELSE a.ipkp END AS isi2, CASE WHEN a.ipak = '0' THEN '-' ELSE a.ipak END AS isi3, e.ipkp AS isi4, e.ipak AS isi5,
        c.ipkp AS isi6, c.ipak AS isi7, f.ipkp AS isi8, f.ipak AS isi9 FROM ots_skd a
        LEFT JOIN (
        SELECT id, kab, CASE WHEN ipkp = '0' THEN '-' ELSE ipkp END AS ipkp, CASE WHEN ipak = '0' THEN '-' ELSE ipak END AS ipak FROM `ots_skd` 
        WHERE trw = 'Triwulan II' AND tahun =:tahun) e ON a.kab = e.kab

        LEFT JOIN (
        SELECT id, kab, CASE WHEN ipkp = '0' THEN '-' ELSE ipkp END AS ipkp, CASE WHEN ipak = '0' THEN '-' ELSE ipak END AS ipak FROM `ots_skd` 
        WHERE trw = 'Triwulan III' AND tahun =:tahun) c ON a.kab = c.kab

        LEFT JOIN (
        SELECT id, kab, CASE WHEN ipkp = '0' THEN '-' ELSE ipkp END AS ipkp, CASE WHEN ipak = '0' THEN '-' ELSE ipak END AS ipak FROM `ots_skd` 
        WHERE trw = 'Triwulan IV' AND tahun =:tahun) f ON a.kab = f.kab

        WHERE trw = 'Triwulan I' AND tahun =:tahun AND a.kab NOT LIKE '3300%'";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $result, "tabel" => "ots_skd"], 200);
	});

	$app->get("/skd_prov_smt/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		do {
			$sqlCek = "SELECT tahun FROM ots_skd WHERE tahun=:tahun AND trw LIKE 'semester%' ORDER BY id DESC LIMIT 1";
			$stmtCek = $this->db->prepare($sqlCek);
			$stmtCek->execute([":tahun" => $tahun]);
			$resultCek = $stmtCek->fetch();
			if ($resultCek == false) {
				$tahun = $tahun - 1;
				if ($tahun == 2022)
					$resultCek = true;
			}
		} while ($resultCek == false);

		if (($lang == "in" || $lang == "id")) {
			$judul = "Hasil Survey Kebutuhan Data BPS Provinsi Jawa Tengah, " . $tahun . " \n(Metode Kemenpan)";
			$kolom = "Semester:Indeks Persepsi Kualitas Pelayanan - IPKP:Indeks Persepsi Anti Korupsi - IPAK";
		} else if ($lang == "en") {
			$judul = "Result of Data Need Survey BPS of Jawa Tengah Province, " . $tahun . " \n(Kemenpan Method)";
			$kolom = "Semester:Service Quality Perception Index - IPKP:Anti Corruption Perception Index";
		}
		$sql = "SELECT trw AS isi1, ipkp AS isi2, ipak AS isi3 FROM ots_skd WHERE tahun=:tahun AND kab LIKE '3300%' AND trw LIKE 'semester%' ORDER BY id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result, "tabel" => "ots_skd"], 200);
	});

	$app->get("/skd_kab_smt/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		//Cek Tahun
		$sqlCek = "SELECT tahun FROM ots_skd WHERE tahun=:tahun AND trw LIKE 'semester%' ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false)
			$tahun = $tahun - 1;

		if (($lang == "in" || $lang == "id")) {
			$judul = "Hasil Survey Kebutuhan Data BPS Kabupaten/Kota di Provinsi Jawa Tengah, " . $tahun;
			$kolom = "Kabupaten/Kota:Semester I:Semester II";
			$kolom1 = "Indeks Persepsi Kualitas Pelayanan - IPKP:Indeks Persepsi Anti Korupsi - IPAK";
		} else if ($lang == "en") {
			$judul = "Result of Data Need Survey for BPS of Regency/Municipality in Jawa Tengah Province, " . $tahun;
			$kolom = "Regency/Municipality:Semester I:Semester II";
			$kolom1 = "Service Quality Perception Index - IPKP:Anti Corruption Perception Index - IPAK";
		}

		$sql = "SELECT a.kab AS isi1, a.ipkp AS isi2, a.ipak AS isi3, e.ipkp AS isi4, e.ipak AS isi5 FROM ots_skd a
        LEFT JOIN (
        SELECT id, kab, CASE WHEN ipkp = '0' THEN '-' ELSE ipkp END AS ipkp, CASE WHEN ipak = '0' THEN '-' ELSE ipak END AS ipak FROM `ots_skd` 
        WHERE trw = 'Semester II' AND tahun =:tahun) e ON a.kab = e.kab

        WHERE trw = 'Semester I' AND tahun =:tahun AND a.kab NOT LIKE '3300%'";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $result, "tabel" => "ots_skd"], 200);
	});

	$app->get("/get_tahun_skd_ann", function (Request $request, Response $response, $args) {
		$sql = "SELECT DISTINCT tahun FROM ots_skd_tahunan ORDER BY tahun DESC";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();
		return $response->withJson(["status" => "success", "data" => $result], 200);
	});

	$app->get("/skd_prov_ann/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];

		if (($lang == "in" || $lang == "id")) {
			$judul = "Hasil Survey Kebutuhan Data BPS Provinsi Jawa Tengah, Tahunan \n(Metode Kemenpan)";
			$kolom = "Tahunan:Indeks Kepuasan Konsumen - IKK:Indeks Persepsi Anti Korupsi - IPAK";
		} else if ($lang == "en") {
			$judul = "Annual Data Need Survey Result BPS of Jawa Tengah Province \n(Kemenpan Method)";
			$kolom = "Annual:Consumer Satisfaction Index - IKK:Anti Corruption Perception Index";
		}
		
		// Ambil data tahunan dari tabel baru ots_skd_tahunan
		$sql = "SELECT tahun AS isi1, ikk AS isi2, ipak AS isi3 FROM ots_skd_tahunan 
		        WHERE kab LIKE '3300%' 
		        ORDER BY tahun DESC LIMIT 5";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result, "tabel" => "ots_skd_tahunan"], 200);
	});

	$app->get("/skd_kab_ann/{tahun}/{lang}", function (Request $request, Response $response, $args) {
		$tahun = $args["tahun"];
		$lang = $args["lang"];
		
		// Cek Tahun di tabel tahunan
		$sqlCek = "SELECT tahun FROM ots_skd_tahunan WHERE tahun=:tahun ORDER BY id DESC LIMIT 1";
		$stmtCek = $this->db->prepare($sqlCek);
		$stmtCek->execute([":tahun" => $tahun]);
		$resultCek = $stmtCek->fetch();
		if ($resultCek == false) {
			$sqlLatest = "SELECT tahun FROM ots_skd_tahunan ORDER BY tahun DESC LIMIT 1";
			$stmtLatest = $this->db->prepare($sqlLatest);
			$stmtLatest->execute();
			$resultLatest = $stmtLatest->fetch();
			$tahun = ($resultLatest) ? $resultLatest['tahun'] : $tahun;
		}

		if (($lang == "in" || $lang == "id")) {
			$judul = "Hasil Survey Kebutuhan Data BPS Kabupaten/Kota di Provinsi Jawa Tengah, " . $tahun;
			$kolom = "Kabupaten/Kota:Tahunan";
			$kolom1 = "Indeks Kepuasan Konsumen - IKK:Indeks Persepsi Anti Korupsi - IPAK";
		} else if ($lang == "en") {
			$judul = "Result of Annual Data Need Survey for BPS of Regency/Municipality in Jawa Tengah Province, " . $tahun;
			$kolom = "Regency/Municipality:Annual";
			$kolom1 = "Consumer Satisfaction Index - IKK:Anti Corruption Perception Index - IPAK";
		}

		$sql = "SELECT kab AS isi1, ikk AS isi2, ipak AS isi3 FROM ots_skd_tahunan 
		        WHERE tahun = :tahun AND kab NOT LIKE '3300%' 
		        ORDER BY kab ASC";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([":tahun" => $tahun]);
		$result = $stmt->fetchAll();
		
		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "kolom1" => $kolom1, "data" => $result, "tabel" => "ots_skd_tahunan"], 200);
	});

	// INDIKATOR RPJPN

	$app->get("/iup/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$tahun = "2023";
		$bulan = "";
		if (($lang == "in" || $lang == "id")) {
			$judul = "Indikator Utama Pembangunan Daerah\nProvinsi Jawa Tengah, Tahun " . $tahun;
			$kolom = " :Indikator:Nilai";
			$sql = "SELECT id AS isi1, REPLACE(nama,'<br>','\n') AS isi2, th2023 AS isi3 FROM ots_ind_rpjpn WHERE tampil = 1";
		} else if ($lang == "en") {
			$judul = "Regional Development Main Indicators\nof Jawa Tengah Province, Year " . $tahun;
			$kolom = " :Indicator:Value";
			// Use nama_en if available, fallback to nama for gradual migration
			$sql = "SELECT id AS isi1, REPLACE(COALESCE(NULLIF(nama_en,''), nama),'<br>','\n') AS isi2, th2023 AS isi3 FROM ots_ind_rpjpn WHERE tampil = 1";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $result], 200);
	});


	$app->get("/rpjpn/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		$tahun = '2019 - 2023';

		if (($lang == "in" || $lang == "id")) {
			$judul = "Indikator Utama Pembangunan Daerah tahun " . $tahun;
			$kolom = "Indikator Utama Pembangunan:2019:2020:2021:2022:2023";
			$tujuan_col = "`tujuan pembangunan`";
			$nama_col = "nama";
		} else if ($lang == "en") {
			$judul = "Regional Development Main Indicators of Jawa Tengah Province, " . $tahun;
			$kolom = "Main Indicator of Regional Development:2019:2020:2021:2022:2023";
			$tujuan_col = "COALESCE(NULLIF(tujuan_pembangunan_en,''), `tujuan pembangunan`)";
			$nama_col = "COALESCE(NULLIF(nama_en,''), nama)";
		}

		$sqltp = "SELECT distinct `tujuan pembangunan` AS x FROM ots_ind_rpjpn";
		$stmt = $this->db->prepare($sqltp);
		$stmt->execute();
		$tp = $stmt->fetchAll();

		for ($i = 0; $i < count($tp); $i++) {
			$sql = "SELECT $tujuan_col AS tujuan_display, $nama_col AS nama_display, nama, th2019, th2020, th2021, th2022, th2023 FROM ots_ind_rpjpn WHERE tampil = 1 AND `tujuan pembangunan`=:tp";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([":tp" => $tp[$i]['x']]);
			$result = $stmt->fetchAll();
			if ($result == null)
				continue;
			$arr[] = $result;
		}

		foreach ($arr as $outerArray) {
			// Get the tujuan pembangunan name from the first element of each inner array
			$tujuan = $outerArray[0]["tujuan_display"];

			// First entry for each "tujuan pembangunan"
			$formattedData[] = [
				"isi1" => "<b>" . $tujuan . "</b>",
				"isi2" => "",
				"isi3" => "",
				"isi4" => "",
				"isi5" => "",
				"isi6" => "",
			];

			// Then add each data row under that tujuan
			foreach ($outerArray as $item) {
				if ($item["nama"] !== "Indeks Ketimpangan Gender (IKG)") {
					$formattedData[] = [
						"isi1" => $item["nama_display"],
						"isi2" => number_format((float) $item["th2019"], 2, '.', ''),
						"isi3" => number_format((float) $item["th2020"], 2, '.', ''),
						"isi4" => number_format((float) $item["th2021"], 2, '.', ''),
						"isi5" => number_format((float) $item["th2022"], 2, '.', ''),
						"isi6" => number_format((float) $item["th2023"], 2, '.', '')
					];
				} else {
					// For "Indeks Ketimpangan Gender", no formatting required
					$formattedData[] = [
						"isi1" => $item["nama_display"],
						"isi2" => $item["th2019"],
						"isi3" => $item["th2020"],
						"isi4" => $item["th2021"],
						"isi5" => $item["th2022"],
						"isi6" => $item["th2023"]
					];
				}
			}
		}

		return $response->withJson(["status" => "success", "judul" => $judul, "tahun" => $tahun, "kolom" => $kolom, "data" => $formattedData], 200);
	});

	$app->get("/trans_sosial/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Indikator Capaian Transformasi Sosial";
			$kolom = "Nama Indikator:Baseline:Nilai";
			$sql = "SELECT nama AS isi1, baseline AS isi2, sasaran AS isi3 FROM ots_ind_rpjpn WHERE id_transformasi = '1' ORDER BY id";
		} else if ($lang == "en") {
			$judul = "Indikator Capaian Transformasi Sosial";
			$kolom = "Nama Indikator:Baseline:Nilai";
			$sql = "SELECT CASE WHEN id_sub != '' THEN CONCAT(' ', id_sub, ' ', nama) ELSE CONCAT(id_indikator, ' ', nama) END AS isi1, baseline AS isi2, sasaran AS isi3 FROM ots_ind_rpjpn WHERE id_transformasi = '1' ORDER BY id";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/trans_ekonomi/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Indikator Capaian Transformasi Ekonomi";
			$kolom = "Nama Indikator:Baseline:Nilai";
			$sql = "SELECT nama AS isi1, baseline AS isi2, sasaran AS isi3 FROM ots_ind_rpjpn WHERE id_transformasi = '2' ORDER BY id";
		} else if ($lang == "en") {
			$judul = "Indikator Capaian Transformasi Sosial";
			$kolom = "Nama Indikator:Baseline:Nilai";
			$sql = "SELECT CASE WHEN id_sub != '' THEN CONCAT(' ', id_sub, ' ', nama) ELSE CONCAT(id_indikator, ' ', nama) END AS isi1, baseline AS isi2, sasaran AS isi3 FROM ots_ind_rpjpn WHERE id_transformasi = '2' ORDER BY id";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/trans_tatakelola/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Indikator Capaian Transformasi Ekonomi";
			$kolom = "Nama Indikator:Baseline:Nilai";
			$sql = "SELECT nama AS isi1, baseline AS isi2, sasaran AS isi3 FROM ots_ind_rpjpn WHERE id_transformasi = '2' ORDER BY id";
		} else if ($lang == "en") {
			$judul = "Indikator Capaian Transformasi Tata Kelola";
			$kolom = "Nama Indikator:Baseline:Nilai";
			$sql = "SELECT CASE WHEN id_sub != '' THEN CONCAT(' ', id_sub, ' ', nama) ELSE CONCAT(id_indikator, ' ', nama) END AS isi1, baseline AS isi2, sasaran AS isi3 FROM ots_ind_rpjpn WHERE id_transformasi = '3' ORDER BY id";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/trans_hukum/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Indikator Capaian Transformasi Ekonomi";
			$kolom = "Nama Indikator:Baseline:Nilai";
			$sql = "SELECT nama AS isi1, baseline AS isi2, sasaran AS isi3 FROM ots_ind_rpjpn WHERE id_transformasi = '2' ORDER BY id";
		} else if ($lang == "en") {
			$judul = "Indikator Capaian Supremasi Hukum, Stabilitas, dan Kepemimpinan Indonesia";
			$kolom = "Nama Indikator:Baseline:Nilai";
			$sql = "SELECT CASE WHEN id_sub != '' THEN CONCAT(' ', id_sub, ' ', nama) ELSE CONCAT(id_indikator, ' ', nama) END AS isi1, baseline AS isi2, sasaran AS isi3 FROM ots_ind_rpjpn WHERE id_transformasi = '4' ORDER BY id";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "kolom" => $kolom, "data" => $result], 200);
	});

	$app->get("/trans_sosbud/{lang}", function (Request $request, Response $response, $args) {
		$lang = $args["lang"];
		if (($lang == "in" || $lang == "id")) {
			$judul = "Indikator Capaian Transformasi Ekonomi";
			$kolom = "Nama Indikator:Baseline:Nilai";
			$sql = "SELECT nama AS isi1, baseline AS isi2, sasaran AS isi3 FROM ots_ind_rpjpn WHERE id_transformasi = '2' ORDER BY id";
		} else if ($lang == "en") {
			$judul = "Indikator Capaian Ketahanan Sosial Budaya dan Ekologi";
			$kolom = "Nama Indikator:Baseline:Nilai";
			$sql = "SELECT CASE WHEN id_sub != '' THEN CONCAT(' ', id_sub, ' ', nama) ELSE CONCAT(id_indikator, ' ', nama) END AS isi1, baseline AS isi2, sasaran AS isi3 FROM ots_ind_rpjpn WHERE id_transformasi = '5' ORDER BY id";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$result = $stmt->fetchAll();

		return $response->withJson(["status" => "success", "judul" => $judul, "kolom" => $kolom, "data" => $result], 200);
	});


	// --- MOCKUP & TESTING ROUTES ---

	// 1. Setup Table: Membuat tabel mainan 'test_sync'
	$app->get("/setup-test", function (Request $request, Response $response) {
		$sql = "CREATE TABLE IF NOT EXISTS test_sync (
            id INT AUTO_INCREMENT PRIMARY KEY,
            indikator VARCHAR(50),
            nilai FLOAT,
            tahun INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
		try {
			$db = $this->db;
			$stmt = $db->prepare($sql);
			$stmt->execute();
			return $response->withJson(["status" => "success", "message" => "Tabel 'test_sync' berhasil dibuat! Siap untuk testing."], 200);
		} catch (PDOException $e) {
			return $response->withJson(["status" => "error", "message" => "Gagal membuat tabel: " . $e->getMessage()], 500);
		}
	});

	// 2. Mock API: Pura-pura menjadi Web API luar (misal BPS)
	$app->get("/mock-api", function (Request $request, Response $response) {
		// Ini contoh data yang ceritanya kita ambil dari website lain
		$data = [
			["indikator" => "Inflasi (Mock)", "nilai" => 2.55, "tahun" => 2024],
			["indikator" => "Kemiskinan (Mock)", "nilai" => 10.7, "tahun" => 2024],
			["indikator" => "Pengangguran (Mock)", "nilai" => 5.13, "tahun" => 2024]
		];
		return $response->withJson($data, 200);
	});

	// 3. Run Sync: Robot otomatis yang memindahkan data
	// [REMOVED] Old mock sync route to avoid conflict


	// 4. View Data: Melihat isi tabel test_sync
	$app->get("/view-test-data", function (Request $request, Response $response) {
		try {
			$sql = "SELECT * FROM test_sync ORDER BY id DESC";
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$result = $stmt->fetchAll();
			return $response->withJson(["status" => "success", "data" => $result], 200);
		} catch (PDOException $e) {
			return $response->withJson(["status" => "error", "message" => "Tabel mungkin belum dibuat. Jalankan /setup-test dulu."], 500);
		}
	});


	// --- REAL SYNC IMPLEMENTATION (STAGING MODE) ---

	// 5. Setup Staging Table: Duplikat struktur table asli ke table test
	$app->get("/setup-real-test", function (Request $request, Response $response) {
		try {
			$db = $this->db;
			// Gunakan IF NOT EXISTS agar data tidak hilang
			$db->exec("CREATE TABLE IF NOT EXISTS ots_inflasi LIKE ots_inflasi");

			// Pastikan kolom "sebelum" ada (jika di ots_inflasi belum ada)
			// Cek kolom dulu atau langsung ADD COLUMN IF NOT EXISTS (MySQL 8)
			// Cara aman: Coba tambahkan, kalau gagal berarti sudah ada (atau pakai procedure)
			// Tapi karena ini tabel test "kosong" hasil copy, kita bisa alter paksa jika di source gak ada.
			// Asumsi: ots_inflasi MUNGKIN belum punya kolom ini, jadi kita tambahkan manual di _test.

			$colsToAdd = [
				"ADD COLUMN IF NOT EXISTS month_sebelum VARCHAR(10) DEFAULT '-'",
				"ADD COLUMN IF NOT EXISTS yoy_sebelum VARCHAR(10) DEFAULT '-'",
				"ADD COLUMN IF NOT EXISTS tahunan_sebelum VARCHAR(10) DEFAULT '-'"
			];

			// MySQL < 8.0 tidak support IF NOT EXISTS di ALTER TABLE msg.
			// Kita cek dulu kolom di ots_inflasi
			$stmt = $db->query("SHOW COLUMNS FROM ots_inflasi");
			$existingCols = $stmt->fetchAll(PDO::FETCH_COLUMN);

			foreach (["month_sebelum", "yoy_sebelum", "tahunan_sebelum", "tahun_sebelum", "nas_month", "nas_th", "nas_yoy", "makanan", "pakaian", "perumahan", "perlengkapan", "kesehatan", "transportasi", "informasi", "rekreasi", "pendidikan", "restoran", "lainnya", "dki", "serang", "bandung", "yogyakarta", "surabaya", "cilacap_yoy", "purwokerto_yoy", "wonosobo_yoy", "wonogiri_yoy", "rembang_yoy", "kudus_yoy", "surakarta_yoy", "semarang_yoy", "tegal_yoy", "cilacap_th", "purwokerto_th", "wonosobo_th", "wonogiri_th", "rembang_th", "kudus_th", "surakarta_th", "semarang_th", "tegal_th"] as $col) {
				if (!in_array($col, $existingCols)) {
					$db->exec("ALTER TABLE ots_inflasi ADD COLUMN $col VARCHAR(10) DEFAULT '-'");
				}
			}

			return $response->withJson(["status" => "success", "message" => "Tabel 'ots_inflasi' berhasil dibuat dan struktur disesuaikan."], 200);
		} catch (PDOException $e) {
			return $response->withJson(["status" => "error", "message" => "Gagal setup table: " . $e->getMessage()], 500);
		}
	});

	$app->get("/setup-ntp-test", function (Request $request, Response $response) {
		$db = $this->db;
		try {
			$db->exec("CREATE TABLE IF NOT EXISTS t_ntp LIKE t_ntp");
			return $response->withJson([
				"status" => "success",
				"message" => "Tabel t_ntp berhasil disiapkan (mirrored dari t_ntp)."
			], 200);
		} catch (Exception $e) {
			return $response->withJson([
				"status" => "error",
				"message" => "Gagal menyiapkan tabel: " . $e->getMessage()
			], 500);
		}
	});

	$app->get("/setup-all-test", function (Request $request, Response $response) {
		$db = $this->db;
		$tables = [
			't_ekspor' => 'ots_ekspor',
			't_impor' => 'ots_impor',
			't_giniratio' => 't_giniratio',
			't_ipm_prov' => 't_ipm_prov',
			't_ipm_kab' => 't_ipm_kab',
			't_miskin_prov' => 't_miskin_prov',
			't_miskin_kab' => 't_miskin_kab',
			't_naker_prov' => 't_naker_prov',
			't_naker_kab' => 't_naker_kab',
			't_neraca' => 't_neraca'
		];

		$created = [];
		$errors = [];

		foreach ($tables as $source => $target) {
			try {
				$db->exec("CREATE TABLE IF NOT EXISTS $target LIKE $source");
				$created[] = $target;
			} catch (Exception $e) {
				$errors[] = "$target ({$e->getMessage()})";
			}
		}

		return $response->withJson([
			"status" => count($errors) > 0 ? "partial_success" : "success",
			"created" => $created,
			"errors" => $errors,
			"message" => "Setup bulk tables selesai."
		], 200);
	});

	$app->get("/run-sync-ntp-test", function (Request $request, Response $response) {
		$db = $this->db;
		$yearParam = $request->getParam('year');
		$year = ots_normalize_year($yearParam ? $yearParam : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;

		error_log("NTP Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		// Optional cleanup
		if ($clean == 'true') {
			$db->exec("TRUNCATE TABLE t_ntp");
			error_log("NTP Sync: Table truncated");
		}

		// URLs for NTP components (Jateng - 3300)
		$urls = [
			'ntp_all' => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/86/th/$bpsYear/key/$key", // General Index (2025 onwards)
			'subsectors' => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/611/th/$bpsYear/key/$key", // Subsectors
			'ntup' => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/1789/th/$bpsYear/key/$key",
			'ntp_regional' => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/1741/th/$bpsYear/key/$key"
		];

		// Fallback/Legacy for 2024 and earlier
		if ($year < 2025) {
			$urls['it_609'] = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/609/th/$bpsYear/key/$key";
			$urls['ib_610'] = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/610/th/$bpsYear/key/$key";
		}

		$results = [];
		foreach ($urls as $tag => $url) {
			// Retry up to 2x jika BPS API rate-limited (HTTP 500)
			for ($attempt = 1; $attempt <= 2; $attempt++) {
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
				curl_setopt($ch, CURLOPT_TIMEOUT, 30);
				$output = curl_exec($ch);
				$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_close($ch);
				$decoded = json_decode($output, true);
				if ($httpCode == 200 && $decoded && isset($decoded['datacontent'])) {
					$results[$tag] = $decoded;
					break;
				}
				if ($attempt < 2) {
					error_log("NTP Sync: $tag attempt $attempt failed (HTTP:$httpCode), retrying in 3s...");
					sleep(3);
				} else {
					$results[$tag] = $decoded;
				}
			}
			sleep(1); // Delay 1 detik antar API untuk anti rate-limiting
		}

		// Mapping Month IDs to Names
		$monthNames = [
			1 => "Januari",
			2 => "Februari",
			3 => "Maret",
			4 => "April",
			5 => "Mei",
			6 => "Juni",
			7 => "Juli",
			8 => "Agustus",
			9 => "September",
			10 => "Oktober",
			11 => "November",
			12 => "Desember"
		];

		$finalData = [];
		for ($m = 1; $m <= 12; $m++) {
			$finalData[$m] = [
				'bulan' => $m,
				'des_bulan' => $monthNames[$m],
				'tahun' => $year
			];
		}

		// 1. Fill General Indices (Var 86 or Var 611)
		foreach (['ntp_all', 'subsectors'] as $tag) {
			if (isset($results[$tag]['datacontent'])) {
				foreach ($results[$tag]['datacontent'] as $k => $v) {
					// Pattern for Var 86 (1860, 2860, 3860) or Var 611 (M611VID)
					if (preg_match('/^(\d)860' . $bpsYear . '(\d{2,3})$/', $k, $matches)) {
						$vid = (int) $matches[1];
						$tid = (int) $matches[2];
						$m = ($tid >= 90 && $tid <= 101) ? $tid - 89 : 0;
						if ($m >= 1 && $m <= 12) {
							$f_tag = ($vid == 1 ? 'it' : ($vid == 2 ? 'ib' : 'ntp'));
							$finalData[$m][$f_tag] = $v;
						}
					} elseif (preg_match('/^(\d{1,2})611(\d{3})' . $bpsYear . '0$/', $k, $matches)) {
						$m = (int) $matches[1];
						$tvid = (int) $matches[2];
						if ($m >= 1 && $m <= 12) {
							switch ($tvid) {
								case 936:
									$finalData[$m]['tanaman_pangan'] = $v;
									break;
								case 937:
									$finalData[$m]['hortikultura'] = $v;
									break;
								case 938:
									$finalData[$m]['tpr'] = $v;
									break;
								case 939:
									$finalData[$m]['peternakan'] = $v;
									break;
								case 940:
									$finalData[$m]['ikan_total'] = $v;
									break;
								case 941:
									$finalData[$m]['ntp'] = $v;
									break; // General NTP in 611
							}
						}
					}
				}
			}
		}

		// It/Ib for 2024
		foreach (['it_609' => '609', 'ib_610' => '610'] as $tag => $vid) {
			if (isset($results[$tag]['datacontent'])) {
				foreach ($results[$tag]['datacontent'] as $k => $v) {
					if (preg_match('/^(\d{1,2})' . $vid . '941' . $bpsYear . '0$/', $k, $matches)) {
						$m = (int) $matches[1];
						$t = ($tag == 'it_609' ? 'it' : 'ib');
						if ($m >= 1 && $m <= 12)
							$finalData[$m][$t] = $v;
					}
				}
			}
		}

		// 2. NTUP (Var 1789, Domain 3300)
		if (isset($results['ntup']['datacontent'])) {
			foreach ($results['ntup']['datacontent'] as $k => $v) {
				// Regular pattern for general NTUP
				if (preg_match('/^33001789(\d)(' . $bpsYear . '|' . ($bpsYear - 1) . ')(\d{3})$/', $k, $matches)) {
					$tid = (int) $matches[3];
					// Map months (Jan=154, Feb=155...)
					$m = ($tid >= 154 && $tid <= 165) ? $tid - 153 : 0;
					if ($m >= 1 && $m <= 12) {
						$finalData[$m]['ntup'] = $v;
					}
				}
			}
		}

		// 3. Regional NTP (Var 1741, Domain 0000)
		if (isset($results['ntp_regional']['datacontent'])) {
			$regMap = [
				'3100' => 'dki',
				'3200' => 'jabar',
				'3400' => 'diy',
				'3500' => 'jatim',
				'3600' => 'banten',
				'9999' => 'nasional'
			];
			foreach ($results['ntp_regional']['datacontent'] as $k => $v) {
				if (preg_match('/^(\d{4})17416' . $bpsYear . '(\d{1,2})$/', $k, $matches)) {
					$prov = $matches[1];
					$m = (int) $matches[2];
					if (isset($regMap[$prov]) && $m >= 1 && $m <= 12) {
						$finalData[$m][$regMap[$prov]] = $v;
					}
				}
			}
		}

		// Calculate tanda/sign/poin/sebelumnya/month_before
		$monthNamesId = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'Nopember', 12 => 'Desember'];
		$monthNamesEn = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

		$prevNtp = null;
		$stmtPrev = $db->prepare("SELECT ntp FROM t_ntp WHERE bulan=12 AND tahun=:t");
		$stmtPrev->execute([':t' => (int) $year - 1]);
		$prevRow = $stmtPrev->fetch();
		if ($prevRow && (float) $prevRow['ntp'] > 0)
			$prevNtp = (float) $prevRow['ntp'];

		for ($m = 1; $m <= 12; $m++) {
			if (!isset($finalData[$m]))
				continue;
			// sebelumnya
			if ($m == 1) {
				$finalData[$m]['sebelumnya'] = 'Desember ' . ((int) $year - 1);
				$finalData[$m]['month_before'] = 'December ' . ((int) $year - 1);
			} else {
				$finalData[$m]['sebelumnya'] = $monthNamesId[$m - 1] . ' ' . $year;
				$finalData[$m]['month_before'] = $monthNamesEn[$m - 1] . ' ' . $year;
			}
			// tanda/sign/poin
			if (isset($finalData[$m]['ntp']) && (float) $finalData[$m]['ntp'] > 0 && $prevNtp !== null) {
				$poin = round((float) $finalData[$m]['ntp'] - $prevNtp, 2);
				$finalData[$m]['tanda'] = ($poin >= 0) ? 'naik' : 'turun';
				$finalData[$m]['sign'] = ($poin >= 0) ? 'increased' : 'decreased';
				$finalData[$m]['poin'] = $poin;
				$prevNtp = (float) $finalData[$m]['ntp'];
			}
		}

		$stats = ots_empty_sync_stats();
		foreach ($finalData as $m => $row) {
			$result = ots_sync_upsert_row($db, 't_ntp', $row, ['bulan', 'tahun'], ['ntp', 'it', 'ib', 'tanaman_pangan', 'hortikultura', 'tpr', 'peternakan', 'ikan_total', 'ntup', 'dki', 'jabar', 'diy', 'jatim', 'banten', 'nasional']);
			ots_add_sync_stat($stats, $result);
		}
		$count = ots_success_count($stats);
		error_log("NTP Sync completed: $count rows processed for $year");
		return $response->withJson(["status" => ots_sync_status($stats), "message" => "Sync selesai ($count data).", "year" => $year, "count" => $count, "summary" => $stats], empty($stats['errors']) ? 200 : 500);
	});

	// 6. Sync Ekspor Data: Tarik dari API BPS (Var 52) -> Masuk ke ots_ekspor
	$app->get("/run-sync-ekspor-test", function (Request $request, Response $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;

		error_log("Ekspor Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		if ($clean == 'true') {
			$deleteResult = ots_delete_rows_by_year($db, 'ots_ekspor', $year);
			if ($deleteResult['action'] === 'error') {
				return $response->withJson(["status" => "error", "message" => "Gagal membersihkan data ekspor.", "detail" => $deleteResult['message']], 500);
			}
			error_log("Ekspor Sync: Deleted existing data for year $year");
		}

		// Fetch Variable 52 (Ekspor & Impor)
		$url = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/52/th/$bpsYear/key/$key";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$output = curl_exec($ch);
		curl_close($ch);
		$data = json_decode($output, true);

		if (!$data || !isset($data['datacontent']) || empty($data['datacontent'])) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API belum memiliki data Ekspor untuk tahun $year.", "count" => 0], 200);
		}

		$monthNames = [
			1 => "Januari",
			2 => "Februari",
			3 => "Maret",
			4 => "April",
			5 => "Mei",
			6 => "Juni",
			7 => "Juli",
			8 => "Agustus",
			9 => "September",
			10 => "Oktober",
			11 => "November",
			12 => "Desember"
		];
		$monthNamesEn = [
			1 => "January",
			2 => "February",
			3 => "March",
			4 => "April",
			5 => "May",
			6 => "June",
			7 => "July",
			8 => "August",
			9 => "September",
			10 => "October",
			11 => "November",
			12 => "December"
		];

		$finalData = [];
		for ($m = 1; $m <= 12; $m++) {
			if ($m == 1) {
				$sebelumnya = "Desember " . ($year - 1);
				$monthBefore = "December " . ($year - 1);
			} else {
				$sebelumnya = $monthNames[$m - 1] . " " . $year;
				$monthBefore = $monthNamesEn[$m - 1] . " " . $year;
			}
			$finalData[$m] = [
				'bulan' => $m,
				'des_bulan' => $monthNames[$m],
				'tahun' => $year,
				'sebelumnya' => $sebelumnya,
				'month_before' => $monthBefore
			];
		}

		// Parse datacontent: key pattern = {vervar}520{bpsYear}{turtahun}
		// vervar: 1=Ekspor, 2=Impor | turtahun: 90-101=Jan-Dec, 102=Tahunan
		foreach ($data['datacontent'] as $k => $v) {
			if (preg_match('/^(\d)520' . $bpsYear . '(\d{2,3})$/', $k, $matches)) {
				$vervar = (int) $matches[1];
				$tid = (int) $matches[2];
				$m = ($tid >= 90 && $tid <= 101) ? $tid - 89 : 0;
				if ($m >= 1 && $m <= 12) {
					if ($vervar == 1) {
						$finalData[$m]['nilai_ekspor'] = $v;
					}
					// vervar 2 = Impor (bisa ditambahkan jika ada kolom impor di tabel)
				}
			}
		}

		// Calculate tanda/sign/poin by comparing with previous month
		$prevVal = null;
		// Get last value from previous year (December)
		$stmtPrev = $db->prepare("SELECT nilai_ekspor FROM ots_ekspor WHERE bulan=12 AND tahun=:t");
		$stmtPrev->execute([':t' => (int) $year - 1]);
		$prevRow = $stmtPrev->fetch();
		if ($prevRow)
			$prevVal = (float) $prevRow['nilai_ekspor'];

		for ($m = 1; $m <= 12; $m++) {
			if (isset($finalData[$m]['nilai_ekspor'])) {
				$val = (float) $finalData[$m]['nilai_ekspor'];
				if ($prevVal !== null) {
					$poin = round($val - $prevVal, 2);
					$finalData[$m]['tanda'] = ($poin >= 0) ? 'naik' : 'turun';
					$finalData[$m]['sign'] = ($poin >= 0) ? 'increased' : 'decreased';
					$finalData[$m]['poin'] = $poin;
				}
				$prevVal = $val;
			}
		}

		$stats = ots_empty_sync_stats();
		foreach ($finalData as $m => $row) {
			$result = ots_sync_upsert_row($db, 'ots_ekspor', $row, ['bulan', 'tahun'], ['nilai_ekspor']);
			ots_add_sync_stat($stats, $result);
		}
		$count = ots_success_count($stats);
		error_log("Ekspor Sync completed: $count rows processed for $year");
		return $response->withJson(["status" => ots_sync_status($stats), "message" => "Sync ekspor selesai ($count data).", "year" => $year, "count" => $count, "summary" => $stats], empty($stats['errors']) ? 200 : 500);
	});


	// 7. Sync Impor Data: Tarik dari API BPS (Var 52) -> Masuk ke ots_impor
	$app->get("/run-sync-impor-test", function (Request $request, Response $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;

		error_log("Impor Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		if ($clean == 'true') {
			$deleteResult = ots_delete_rows_by_year($db, 'ots_impor', $year);
			if ($deleteResult['action'] === 'error') {
				return $response->withJson(["status" => "error", "message" => "Gagal membersihkan data impor.", "detail" => $deleteResult['message']], 500);
			}
			error_log("Impor Sync: Deleted existing data for year $year");
		}

		// Fetch Variable 52 (Ekspor & Impor)
		$url = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/52/th/$bpsYear/key/$key";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$output = curl_exec($ch);
		curl_close($ch);
		$data = json_decode($output, true);

		if (!$data || !isset($data['datacontent']) || empty($data['datacontent'])) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API belum memiliki data Impor untuk tahun $year.", "count" => 0], 200);
		}

		$monthNames = [
			1 => "Januari",
			2 => "Februari",
			3 => "Maret",
			4 => "April",
			5 => "Mei",
			6 => "Juni",
			7 => "Juli",
			8 => "Agustus",
			9 => "September",
			10 => "Oktober",
			11 => "November",
			12 => "Desember"
		];

		$finalData = [];
		for ($m = 1; $m <= 12; $m++) {
			if ($m == 1) {
				$sebelumnya = "Desember " . ($year - 1);
			} else {
				$sebelumnya = $monthNames[$m - 1] . " " . $year;
			}
			$finalData[$m] = [
				'bulan' => $m,
				'des_bulan' => $monthNames[$m],
				'tahun' => $year,
				'sebelumnya' => $sebelumnya
			];
		}

		// Parse datacontent: key pattern = {vervar}520{bpsYear}{turtahun}
		// vervar: 2=Impor | turtahun: 90-101=Jan-Dec
		foreach ($data['datacontent'] as $k => $v) {
			if (preg_match('/^(\d)520' . $bpsYear . '(\d{2,3})$/', $k, $matches)) {
				$vervar = (int) $matches[1];
				$tid = (int) $matches[2];
				$m = ($tid >= 90 && $tid <= 101) ? $tid - 89 : 0;
				if ($m >= 1 && $m <= 12 && $vervar == 2) {
					$finalData[$m]['nilai_impor'] = $v;
				}
			}
		}

		// Calculate tanda/sign/poin by comparing with previous month
		$prevVal = null;
		$stmtPrev = $db->prepare("SELECT nilai_impor FROM ots_impor WHERE bulan=12 AND tahun=:t");
		$stmtPrev->execute([':t' => (int) $year - 1]);
		$prevRow = $stmtPrev->fetch();
		if ($prevRow)
			$prevVal = (float) $prevRow['nilai_impor'];

		for ($m = 1; $m <= 12; $m++) {
			if (isset($finalData[$m]['nilai_impor'])) {
				$val = (float) $finalData[$m]['nilai_impor'];
				if ($prevVal !== null) {
					$poin = round($val - $prevVal, 2);
					$finalData[$m]['tanda'] = ($poin >= 0) ? 'naik' : 'turun';
					$finalData[$m]['sign'] = ($poin >= 0) ? 'increased' : 'decreased';
					$finalData[$m]['poin'] = $poin;
				}
				$prevVal = $val;
			}
		}

		$stats = ots_empty_sync_stats();
		foreach ($finalData as $m => $row) {
			$result = ots_sync_upsert_row($db, 'ots_impor', $row, ['bulan', 'tahun'], ['nilai_impor']);
			ots_add_sync_stat($stats, $result);
		}
		$count = ots_success_count($stats);
		error_log("Impor Sync completed: $count rows processed for $year");
		return $response->withJson(["status" => ots_sync_status($stats), "message" => "Sync impor selesai ($count data).", "year" => $year, "count" => $count, "summary" => $stats], empty($stats['errors']) ? 200 : 500);
	});


	// 8. Sync Neraca Data: Tarik dari API BPS (Var 1787) -> Masuk ke t_neraca
	$app->get("/run-sync-neraca-test", function (Request $request, Response $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;

		error_log("Neraca Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		if ($clean == 'true') {
			$deleteResult = ots_delete_rows_by_year($db, 't_neraca', $year);
			if ($deleteResult['action'] === 'error') {
				return $response->withJson(["status" => "error", "message" => "Gagal membersihkan data neraca.", "detail" => $deleteResult['message']], 500);
			}
			error_log("Neraca Sync: Deleted existing data for year $year");
		}

		// Pastikan AUTO_INCREMENT
		try {
			$db->exec("ALTER TABLE t_neraca MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
		} catch (Exception $e) {
		}

		// Fetch Variable 1787 (Neraca Perdagangan)
		// vervar: 1=Migas, 2=Non Migas, 3=Jumlah
		$url = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/1787/th/$bpsYear/key/$key";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$output = curl_exec($ch);
		curl_close($ch);
		$data = json_decode($output, true);

		if (!$data || !isset($data['datacontent']) || empty($data['datacontent'])) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API belum memiliki data Neraca untuk tahun $year.", "count" => 0], 200);
		}

		$monthNames = [
			1 => "Januari",
			2 => "Februari",
			3 => "Maret",
			4 => "April",
			5 => "Mei",
			6 => "Juni",
			7 => "Juli",
			8 => "Agustus",
			9 => "September",
			10 => "Oktober",
			11 => "November",
			12 => "Desember"
		];

		$finalData = [];
		for ($m = 1; $m <= 12; $m++) {
			if ($m == 1) {
				$sebelumnya = "Desember " . ($year - 1);
			} else {
				$sebelumnya = $monthNames[$m - 1] . " " . $year;
			}
			$finalData[$m] = [
				'bulan' => $m,
				'des_bulan' => $monthNames[$m],
				'tahun' => $year,
				'sebelumnya' => $sebelumnya
			];
		}

		// Parse datacontent: key pattern = {vervar}17870{bpsYear}{turtahun}
		// vervar: 3=Jumlah (Total Neraca) | turtahun: 90-101=Jan-Dec
		foreach ($data['datacontent'] as $k => $v) {
			if (preg_match('/^(\d)17870' . $bpsYear . '(\d{2,3})$/', $k, $matches)) {
				$vervar = (int) $matches[1];
				$tid = (int) $matches[2];
				$m = ($tid >= 90 && $tid <= 101) ? $tid - 89 : 0;
				if ($m >= 1 && $m <= 12 && $vervar == 3) {
					$finalData[$m]['nilai'] = $v;
					$finalData[$m]['tanda'] = ($v >= 0) ? "surplus" : "defisit";
					$finalData[$m]['sign'] = ($v >= 0) ? "surplus" : "deficit";
				}
			}
		}

		// Hitung kumulatif (nilai_kum)
		$kum = 0;
		for ($m = 1; $m <= 12; $m++) {
			if (isset($finalData[$m]['nilai'])) {
				$kum += $finalData[$m]['nilai'];
				$finalData[$m]['nilai_kum'] = round($kum, 2);
				$finalData[$m]['tanda_kum'] = ($kum >= 0) ? "surplus" : "defisit";
			}
		}

		$stats = ots_empty_sync_stats();
		foreach ($finalData as $m => $row) {
			$result = ots_sync_upsert_row($db, 't_neraca', $row, ['bulan', 'tahun'], ['nilai']);
			ots_add_sync_stat($stats, $result);
		}
		$count = ots_success_count($stats);
		error_log("Neraca Sync completed: $count rows processed for $year");
		return $response->withJson(["status" => ots_sync_status($stats), "message" => "Sync neraca selesai ($count data).", "year" => $year, "count" => $count, "summary" => $stats], empty($stats['errors']) ? 200 : 500);
	});


	// 9. Sync Miskin Kab Data: Tarik dari API BPS (Var 34) -> Masuk ke t_miskin_kab
	$app->get("/run-sync-miskin-kab-test", function (Request $request, Response $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;

		error_log("Miskin Kab Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		if ($clean == 'true') {
			$deleteResult = ots_delete_rows_by_year($db, 't_miskin_kab', $year);
			if ($deleteResult['action'] === 'error') {
				return $response->withJson(["status" => "error", "message" => "Gagal membersihkan data kemiskinan kab/kota.", "detail" => $deleteResult['message']], 500);
			}
			error_log("Miskin Kab Sync: Deleted existing data for year $year");
		}

		// Pastikan AUTO_INCREMENT
		try {
			$db->exec("ALTER TABLE t_miskin_kab MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
		} catch (Exception $e) {
		}

		// Fetch Variable 34 (Kemiskinan Menurut Kabupaten/Kota)
		// turvar: 49=Garis Kemiskinan, 50=Jumlah Penduduk Miskin, 55=Persentase Penduduk Miskin
		// vervar: kab/kota codes (3300-3376)
		$urls = [
			34 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/34/th/$bpsYear/key/$key",
			77 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/77/th/$bpsYear/key/$key",
			78 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/78/th/$bpsYear/key/$key"
		];

		$apiData = [];
		foreach ($urls as $varId => $url) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			$output = curl_exec($ch);
			curl_close($ch);
			$decoded = json_decode($output, true);
			if ($decoded && isset($decoded['datacontent'])) {
				$apiData[$varId] = $decoded;
			}
		}

		if (empty($apiData)) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API belum memiliki data Kemiskinan Kab untuk tahun $year.", "count" => 0], 200);
		}

		// Build vervar lookup: kab_code => label (from Var 34)
		$kabLabels = [];
		$srcData = isset($apiData[34]) ? $apiData[34] : reset($apiData);
		if (isset($srcData['vervar'])) {
			foreach ($srcData['vervar'] as $vv) {
				$code = (string) $vv['val'];
				// Remove code prefix: "3301 Kabupaten Cilacap" -> "Kabupaten Cilacap"
				$label = preg_replace('/^\d+\s+/', '', $vv['label']);
				$kabLabels[$code] = $label;
			}
		}

		$finalData = [];

		// 1. Parse Var 34 datacontent: key = {kabCode}34{turvar}{bpsYear}{turtahun}
		//    turvar: 49=kab_gk, 50=kab_miskin_jmh, 55=kab_p0
		if (isset($apiData[34])) {
			foreach ($apiData[34]['datacontent'] as $k => $v) {
				if (preg_match('/^(\d{4})34(\d{2})' . $bpsYear . '(\d+)$/', $k, $matches)) {
					$kabCode = $matches[1];
					$turvar = (int) $matches[2];
					$turtahun = (int) $matches[3];
					if ($turtahun !== 0)
						continue;
					if ($kabCode == '3300')
						continue;

					if (!isset($finalData[$kabCode])) {
						$finalData[$kabCode] = [
							'tahun' => $year,
							'bulan' => 3,
							'des_bulan' => 'Maret',
							'kab' => isset($kabLabels[$kabCode]) ? $kabLabels[$kabCode] : $kabCode
						];
					}

					if ($turvar == 49) {
						$finalData[$kabCode]['kab_gk'] = $v;
					} elseif ($turvar == 50) {
						$finalData[$kabCode]['kab_miskin_jmh'] = $v;
					} elseif ($turvar == 55) {
						$finalData[$kabCode]['kab_p0'] = $v;
					}
				}
			}
		}

		// 2. Parse Var 77 datacontent (P1): key = {kabCode}770{bpsYear}{turtahun}
		if (isset($apiData[77])) {
			foreach ($apiData[77]['datacontent'] as $k => $v) {
				if (preg_match('/^(\d{4})770' . $bpsYear . '(\d+)$/', $k, $matches)) {
					$kabCode = $matches[1];
					$turtahun = (int) $matches[2];
					if ($turtahun !== 0)
						continue;
					if ($kabCode == '3300')
						continue;

					if (!isset($finalData[$kabCode])) {
						$finalData[$kabCode] = [
							'tahun' => $year,
							'bulan' => 3,
							'des_bulan' => 'Maret',
							'kab' => isset($kabLabels[$kabCode]) ? $kabLabels[$kabCode] : $kabCode
						];
					}
					$finalData[$kabCode]['kab_p1'] = $v;
				}
			}
		}

		// 3. Parse Var 78 datacontent (P2): key = {kabCode}780{bpsYear}{turtahun}
		if (isset($apiData[78])) {
			foreach ($apiData[78]['datacontent'] as $k => $v) {
				if (preg_match('/^(\d{4})780' . $bpsYear . '(\d+)$/', $k, $matches)) {
					$kabCode = $matches[1];
					$turtahun = (int) $matches[2];
					if ($turtahun !== 0)
						continue;
					if ($kabCode == '3300')
						continue;

					if (!isset($finalData[$kabCode])) {
						$finalData[$kabCode] = [
							'tahun' => $year,
							'bulan' => 3,
							'des_bulan' => 'Maret',
							'kab' => isset($kabLabels[$kabCode]) ? $kabLabels[$kabCode] : $kabCode
						];
					}
					$finalData[$kabCode]['kab_p2'] = $v;
				}
			}
		}

		// Sort by kab code ascending
		ksort($finalData);

		$stats = ots_empty_sync_stats();
		foreach ($finalData as $kabCode => $row) {
			$result = ots_sync_upsert_row($db, 't_miskin_kab', $row, ['kab', 'tahun'], ['kab_gk', 'kab_miskin_jmh', 'kab_p0', 'kab_p1', 'kab_p2']);
			ots_add_sync_stat($stats, $result);
		}
		$count = ots_success_count($stats);
		error_log("Miskin Kab Sync completed: $count rows processed for $year");
		return $response->withJson(["status" => ots_sync_status($stats), "message" => "Sync miskin kab selesai ($count kab/kota).", "year" => $year, "count" => $count, "summary" => $stats], empty($stats['errors']) ? 200 : 500);
	});

	// 10. Sync Miskin Prov Data: Tarik dari API BPS (Var 195, 624, 622, 623) -> Masuk ke t_miskin_prov
	$app->get("/run-sync-miskin-prov-test", function (Request $request, Response $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;
		$provCode = "3300"; // Jawa Tengah

		error_log("Miskin Prov Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		if ($clean == 'true') {
			$deleteResult = ots_delete_rows_by_year($db, 't_miskin_prov', $year);
			if ($deleteResult['action'] === 'error') {
				return $response->withJson(["status" => "error", "message" => "Gagal membersihkan data kemiskinan provinsi.", "detail" => $deleteResult['message']], 500);
			}
			error_log("Miskin Prov Sync: Deleted existing data for year $year");
		}

		// Pastikan AUTO_INCREMENT
		try {
			$db->exec("ALTER TABLE t_miskin_prov MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
		} catch (Exception $e) {
		}

		// Fetch semua API
		$urls = [
			195 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/195/th/$bpsYear/key/$key",
			624 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/624/th/$bpsYear/key/$key",
			622 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/622/th/$bpsYear/key/$key",
			623 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/623/th/$bpsYear/key/$key"
		];

		$apiData = [];
		foreach ($urls as $varId => $url) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			$output = curl_exec($ch);
			curl_close($ch);
			$decoded = json_decode($output, true);
			if ($decoded && isset($decoded['datacontent'])) {
				$apiData[$varId] = $decoded;
			}
		}

		if (empty($apiData)) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API belum memiliki data Kemiskinan Prov untuk tahun $year.", "count" => 0], 200);
		}

		// Prepare row data
		$rowData = [
			'tahun' => $year,
			'bulan' => 3,
			'des_bulan' => 'Maret'
		];

		// 1. Parse Var 195 (Garis Kemiskinan per Provinsi, split Kota/Desa)
		//    Key: {provCode}195{turvar}{bpsYear}{turtahun}
		//    turvar: 430=Perkotaan, 431=Perdesaan
		//    turtahun: 61=Semester 1 (Maret)
		if (isset($apiData[195])) {
			$dc = $apiData[195]['datacontent'];
			// GK Kota (turvar=430, Maret=61)
			$keyKota = $provCode . "195430" . $bpsYear . "61";
			if (isset($dc[$keyKota])) {
				$rowData['gk_kota'] = $dc[$keyKota];
			}
			// GK Desa (turvar=431, Maret=61)
			$keyDesa = $provCode . "195431" . $bpsYear . "61";
			if (isset($dc[$keyDesa])) {
				$rowData['gk_desa'] = $dc[$keyDesa];
			}
		}

		// 2. Parse Var 624 (Garis Kemiskinan Kota+Desa)
		//    Key: {provCode}6240{bpsYear}0
		if (isset($apiData[624])) {
			$dc = $apiData[624]['datacontent'];
			$keyGK = $provCode . "6240" . $bpsYear . "0";
			if (isset($dc[$keyGK])) {
				$rowData['gk_kotadesa'] = $dc[$keyGK];
			}
		}

		// 3. Parse Var 622 (P1 Kedalaman Kemiskinan)
		//    Key: {provCode}6220{bpsYear}0
		if (isset($apiData[622])) {
			$dc = $apiData[622]['datacontent'];
			$keyP1 = $provCode . "6220" . $bpsYear . "0";
			if (isset($dc[$keyP1])) {
				$rowData['p1_kotadesa'] = $dc[$keyP1];
			}
		}

		// 4. Parse Var 623 (P2 Keparahan Kemiskinan)
		//    Key: {provCode}6230{bpsYear}0
		if (isset($apiData[623])) {
			$dc = $apiData[623]['datacontent'];
			$keyP2 = $provCode . "6230" . $bpsYear . "0";
			if (isset($dc[$keyP2])) {
				$rowData['p2_kotadesa'] = $dc[$keyP2];
			}
		}

		$stats = ots_empty_sync_stats();
		$result = ots_sync_upsert_row($db, 't_miskin_prov', $rowData, ['tahun', 'bulan'], ['gk_kota', 'gk_desa', 'gk_kotadesa', 'p0_kotadesa', 'p1_kotadesa', 'p2_kotadesa']);
		ots_add_sync_stat($stats, $result);
		$action = isset($result['action']) && $result['action'] !== 'skipped' ? ucfirst($result['action']) : 'Skipped';
		$count = ots_success_count($stats);

		error_log("Miskin Prov Sync completed: $action $count row for $year");
		return $response->withJson([
			"status" => ots_sync_status($stats),
			"message" => "Sync miskin prov selesai ($action $count row).",
			"year" => $year,
			"count" => $count,
			"summary" => $stats,
			"data" => $rowData
		], empty($stats['errors']) ? 200 : 500);
	});

	// 11. Sync Gini Ratio Data: Tarik dari API BPS (Var 98) -> Masuk ke t_giniratio
	$app->get("/run-sync-giniratio-test", function (Request $request, Response $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;
		$provCode = "3300"; // Jawa Tengah

		error_log("Gini Ratio Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		if ($clean == 'true') {
			$deleteResult = ots_delete_rows_by_year($db, 't_giniratio', $year);
			if ($deleteResult['action'] === 'error') {
				return $response->withJson(["status" => "error", "message" => "Gagal membersihkan data gini ratio.", "detail" => $deleteResult['message']], 500);
			}
			error_log("Gini Ratio Sync: Deleted existing data for year $year");
		}

		// Pastikan AUTO_INCREMENT
		try {
			$db->exec("ALTER TABLE t_giniratio MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
		} catch (Exception $e) {
		}

		// Fetch API Var 98 (Gini Ratio Menurut Provinsi dan Daerah)
		$url = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/98/th/$bpsYear/key/$key";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$output = curl_exec($ch);
		curl_close($ch);
		$data = json_decode($output, true);

		if (!$data || !isset($data['datacontent'])) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API belum memiliki data Gini Ratio untuk tahun $year.", "count" => 0], 200);
		}

		// Prepare row data
		// Key: {provCode}98{turvar}{bpsYear}{turtahun}
		// turvar: 189=Perkotaan, 190=Perdesaan, 191=Perkotaan+Perdesaan
		// turtahun: 61=Semester 1 (Maret)
		$dc = $data['datacontent'];
		$rowData = [
			'tahun' => $year,
			'bulan' => '3',
			'des_bulan' => 'Maret'
		];

		// GR Kota (turvar=189, Maret=61)
		$keyKota = $provCode . "98189" . $bpsYear . "61";
		if (isset($dc[$keyKota])) {
			$rowData['gr_kota'] = $dc[$keyKota];
		}

		// GR Desa (turvar=190, Maret=61)
		$keyDesa = $provCode . "98190" . $bpsYear . "61";
		if (isset($dc[$keyDesa])) {
			$rowData['gr_desa'] = $dc[$keyDesa];
		}

		// GR Kota+Desa (turvar=191, Maret=61)
		$keyKotaDesa = $provCode . "98191" . $bpsYear . "61";
		if (isset($dc[$keyKotaDesa])) {
			$rowData['gr_desakota'] = $dc[$keyKotaDesa];
		}

		// Kolom sebelumnya dan month_before: tahun sebelumnya dengan bulan yang sama
		$prevYear = (int) $year - 1;
		$bulanEngMap = [
			'Januari' => 'January',
			'Februari' => 'February',
			'Maret' => 'March',
			'April' => 'April',
			'Mei' => 'May',
			'Juni' => 'June',
			'Juli' => 'July',
			'Agustus' => 'August',
			'September' => 'September',
			'Oktober' => 'October',
			'November' => 'November',
			'Desember' => 'December'
		];
		$desBulan = $rowData['des_bulan'];
		$rowData['sebelumnya'] = $desBulan . ' ' . $prevYear;
		$bulanEng = isset($bulanEngMap[$desBulan]) ? $bulanEngMap[$desBulan] : $desBulan;
		$rowData['month_before'] = $bulanEng . ' ' . $prevYear;

		// Fetch data tahun sebelumnya untuk perbandingan (tanda, sign, poin)
		$prevBpsYear = $bpsYear - 1;
		$urlPrev = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/98/th/$prevBpsYear/key/$key";
		$chPrev = curl_init();
		curl_setopt($chPrev, CURLOPT_URL, $urlPrev);
		curl_setopt($chPrev, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($chPrev, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($chPrev, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
		curl_setopt($chPrev, CURLOPT_TIMEOUT, 30);
		$outputPrev = curl_exec($chPrev);
		curl_close($chPrev);
		$dataPrev = json_decode($outputPrev, true);

		if ($dataPrev && isset($dataPrev['datacontent'])) {
			// GR Kota+Desa tahun sebelumnya (turvar=191, Maret=61)
			$keyPrev = $provCode . "98191" . $prevBpsYear . "61";
			if (isset($dataPrev['datacontent'][$keyPrev]) && isset($rowData['gr_desakota'])) {
				$grPrev = $dataPrev['datacontent'][$keyPrev];
				$grCurr = $rowData['gr_desakota'];
				$diff = round($grCurr - $grPrev, 3);

				if ($grCurr > $grPrev) {
					$rowData['tanda'] = 'naik';
					$rowData['sign'] = 'increased';
				} elseif ($grCurr < $grPrev) {
					$rowData['tanda'] = 'turun';
					$rowData['sign'] = 'decreased';
				} else {
					$rowData['tanda'] = 'tetap';
					$rowData['sign'] = 'equal';
				}
				$rowData['poin'] = abs($diff);
			}
		}

		$stats = ots_empty_sync_stats();
		$result = ots_sync_upsert_row($db, 't_giniratio', $rowData, ['tahun', 'bulan'], ['gr_kota', 'gr_desa', 'gr_desakota']);
		ots_add_sync_stat($stats, $result);
		$action = isset($result['action']) && $result['action'] !== 'skipped' ? ucfirst($result['action']) : 'Skipped';
		$count = ots_success_count($stats);

		error_log("Gini Ratio Sync completed: $action $count row for $year");
		return $response->withJson([
			"status" => ots_sync_status($stats),
			"message" => "Sync gini ratio selesai ($action $count row).",
			"year" => $year,
			"count" => $count,
			"summary" => $stats,
			"data" => $rowData
		], empty($stats['errors']) ? 200 : 500);
	});

	// 12. Sync IPM Prov Data: Tarik dari API BPS (Var 83 IPM Kab + Var 2207 IPM Nasional) -> Masuk ke t_ipm_prov
	$app->get("/run-sync-ipm-prov-test", function (Request $request, Response $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;

		error_log("IPM Prov Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		if ($clean == 'true') {
			$deleteResult = ots_delete_rows_by_year($db, 't_ipm_prov', $year);
			if ($deleteResult['action'] === 'error') {
				return $response->withJson(["status" => "error", "message" => "Gagal membersihkan data IPM provinsi.", "detail" => $deleteResult['message']], 500);
			}
			error_log("IPM Prov Sync: Deleted existing data for year $year");
		}

		// Pastikan AUTO_INCREMENT
		try {
			$db->exec("ALTER TABLE t_ipm_prov MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
		} catch (Exception $e) {
		}

		// Fetch APIs
		// Var 83: IPM per Kab/Kota (turvar: 131=AHH, 132=HLS, 133=RLS, 134=PPP, 135=IPM), vervar 3300 = Provinsi
		// Var 2207: IPM Nasional per Provinsi (turvar: 0), domain 0000, vervar 3300=Jateng, 9999=Indonesia
		$urls = [
			83 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/83/th/$bpsYear/key/$key",
			2207 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/2207/th/$bpsYear/key/$key"
		];

		$apiData = [];
		foreach ($urls as $varId => $url) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			$output = curl_exec($ch);
			curl_close($ch);
			$decoded = json_decode($output, true);
			if ($decoded && isset($decoded['datacontent'])) {
				$apiData[$varId] = $decoded;
			}
		}

		if (empty($apiData)) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API belum memiliki data IPM Prov untuk tahun $year.", "count" => 0], 200);
		}

		// turvar -> column mapping for Var 83
		$turvarMap = [
			131 => 'ahh',
			132 => 'hls',
			133 => 'rls',
			134 => 'ppp',
			135 => 'ipm'
		];

		$row = ['tahun' => $year];

		// 1. Parse Var 83 for province level (vervar=3300): key = {provCode}83{turvar}{bpsYear}{turtahun}
		if (isset($apiData[83])) {
			foreach ($apiData[83]['datacontent'] as $k => $v) {
				if (preg_match('/^330083(\d{3})' . $bpsYear . '(\d+)$/', $k, $matches)) {
					$turvar = (int) $matches[1];
					$turtahun = (int) $matches[2];
					if ($turtahun !== 0)
						continue;
					if (isset($turvarMap[$turvar])) {
						$row[$turvarMap[$turvar]] = $v;
					}
				}
			}
		}

		// 2. Parse Var 2207 for national IPM: key = {provCode}22070{bpsYear}{turtahun}
		//    vervar 9999 = Indonesia (nasional)
		if (isset($apiData[2207])) {
			foreach ($apiData[2207]['datacontent'] as $k => $v) {
				// nasional: 9999220701250
				if (preg_match('/^999922070' . $bpsYear . '(\d+)$/', $k, $matches)) {
					$turtahun = (int) $matches[1];
					if ($turtahun !== 0)
						continue;
					$row['ipm_nas'] = $v;
				}
			}
		}

		// 3. Calculate GROWTH from previous year DB data
		$prevYear = (int) $year - 1;
		$stmtPrev = $db->prepare("SELECT ipm, ahh, hls, rls, ppp, ipm_nas FROM t_ipm_prov WHERE tahun = :t");
		$stmtPrev->execute([':t' => $prevYear]);
		$prevRow = $stmtPrev->fetch();

		if ($prevRow && isset($row['ipm']) && $prevRow['ipm'] > 0) {
			$row['ipm_growth'] = round((float) $row['ipm'] - (float) $prevRow['ipm'], 2);
			$row['ahh_growth'] = isset($row['ahh']) ? round((float) $row['ahh'] - (float) $prevRow['ahh'], 2) : 0;
			$row['hls_growth'] = isset($row['hls']) ? round((float) $row['hls'] - (float) $prevRow['hls'], 2) : 0;
			$row['rls_growth'] = isset($row['rls']) ? round((float) $row['rls'] - (float) $prevRow['rls'], 2) : 0;
			$row['ppp_growth'] = isset($row['ppp']) ? round((float) $row['ppp'] - (float) $prevRow['ppp'], 2) : 0;
			$row['tanda'] = ($row['ipm_growth'] >= 0) ? 'Meningkat' : 'Menurun';
			$row['sign'] = ($row['ipm_growth'] >= 0) ? 'Increased' : 'Decreased';
			$row['sebelumnya'] = (string) $prevYear;
		}
		if ($prevRow && isset($row['ipm_nas']) && isset($prevRow['ipm_nas']) && $prevRow['ipm_nas'] > 0) {
			$row['ipm_nas_growth'] = round((float) $row['ipm_nas'] - (float) $prevRow['ipm_nas'], 2);
		}

		// Skip if BPS returned no data (ipm is 0 or not set)
		if (!isset($row['ipm']) || (float) $row['ipm'] == 0) {
			return $response->withJson(["status" => "success", "message" => "Tidak ada data IPM baru dari BPS untuk tahun $year (data existing tetap dipertahankan).", "year" => $year], 200);
		}

		$stats = ots_empty_sync_stats();
		$result = ots_sync_upsert_row($db, 't_ipm_prov', $row, ['tahun'], ['ipm']);
		ots_add_sync_stat($stats, $result);
		$count = ots_success_count($stats);

		error_log("IPM Prov Sync completed for $year");
		return $response->withJson([
			"status" => ots_sync_status($stats),
			"message" => "Sync IPM prov selesai.",
			"year" => $year,
			"count" => $count,
			"summary" => $stats,
			"data" => $row
		], empty($stats['errors']) ? 200 : 500);
	});

	// 13. Sync IPM Kab Data: Tarik dari API BPS (Var 83) -> Masuk ke t_ipm_kab
	$app->get("/run-sync-ipm-kab-test", function (Request $request, Response $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;

		error_log("IPM Kab Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		if ($clean == 'true') {
			$deleteResult = ots_delete_rows_by_year($db, 't_ipm_kab', $year);
			if ($deleteResult['action'] === 'error') {
				return $response->withJson(["status" => "error", "message" => "Gagal membersihkan data IPM kab/kota.", "detail" => $deleteResult['message']], 500);
			}
			error_log("IPM Kab Sync: Deleted existing data for year $year");
		}

		// Pastikan AUTO_INCREMENT
		try {
			$db->exec("ALTER TABLE t_ipm_kab MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
		} catch (Exception $e) {
		}

		// Fetch API
		// Var 83: IPM per Kab/Kota (turvar: 131=AHH, 132=HLS, 133=RLS, 134=PPP, 135=IPM)
		$url = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/83/th/$bpsYear/key/$key";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$output = curl_exec($ch);
		curl_close($ch);
		$decoded = json_decode($output, true);

		if (!$decoded || !isset($decoded['datacontent'])) {
			error_log("IPM Kab Sync: Var 83 data not available for year $year, will try Var 2034");
			// Don't return error - continue to Var 2034 below
			$decoded = ['datacontent' => [], 'vervar' => []];
		}

		// Build kabCode => namaKab from vervar
		$kabCodeToName = [];
		if (isset($decoded['vervar'])) {
			foreach ($decoded['vervar'] as $vv) {
				$kabCode = (string) $vv['val'];
				$namaKab = preg_replace('/^\d+\s+/', '', $vv['label']);
				$kabCodeToName[$kabCode] = $namaKab;
			}
		}

		// turvar -> column mapping
		$turvarMap = [
			131 => 'ahh',
			132 => 'hls',
			133 => 'rls',
			134 => 'ppp',
			135 => 'ipm'
		];

		$finalData = []; // kabCode => row

		// Parse datacontent: key = {kabCode}83{turvar}{bpsYear}{turtahun}
		foreach ($decoded['datacontent'] as $k => $v) {
			if (preg_match('/^(\d{4})83(\d{3})' . $bpsYear . '(\d+)$/', $k, $matches)) {
				$kabCode = $matches[1];
				$turvar = (int) $matches[2];
				$turtahun = (int) $matches[3];
				if ($turtahun !== 0)
					continue;
				// Skip province level (3300)
				if ($kabCode == '3300')
					continue;
				if (!isset($turvarMap[$turvar]))
					continue;

				if (!isset($finalData[$kabCode])) {
					$kabSuffix = substr($kabCode, 2); // "3301" -> "01"
					$finalData[$kabCode] = [
						'tahun' => $year,
						'kab' => $kabSuffix,
						'nama_kab' => isset($kabCodeToName[$kabCode]) ? $kabCodeToName[$kabCode] : $kabCode
					];
				}
				$finalData[$kabCode][$turvarMap[$turvar]] = $v;
			}
		}

		ksort($finalData);

		// === STEP 2: Fetch IPM Metode Baru from Var 2034 API ===
		// Var 2034: [Metode Baru] IPM menurut Kab/Kota (UHH Hasil Long Form SP2020)
		// Key format: {kabCode}20340{bpsYear}0  e.g. 330120340125​0
		$urlIpmBaru = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/2034/th/$bpsYear/key/$key";
		$chIpm2 = curl_init();
		curl_setopt($chIpm2, CURLOPT_URL, $urlIpmBaru);
		curl_setopt($chIpm2, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($chIpm2, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($chIpm2, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
		curl_setopt($chIpm2, CURLOPT_TIMEOUT, 30);
		$outputIpm2 = curl_exec($chIpm2);
		curl_close($chIpm2);
		$dataIpm2 = json_decode($outputIpm2, true);

		if ($dataIpm2 && isset($dataIpm2['datacontent'])) {
			// Build kab name map from Var 2034's vervar if we don't have names yet
			$kabNames2034 = [];
			if (isset($dataIpm2['vervar'])) {
				foreach ($dataIpm2['vervar'] as $vv2) {
					$code2 = (string) $vv2['val'];
					$name2 = preg_replace('/^\d+\s+/', '', $vv2['label']);
					$kabNames2034[$code2] = $name2;
				}
			}

			$ipm2Count = 0;
			foreach ($dataIpm2['datacontent'] as $k2 => $v2) {
				// Pattern: {kabCode}20340{bpsYear}0
				if (preg_match('/^(\d{4})20340' . $bpsYear . '0$/', $k2, $m2)) {
					$kabCode2 = $m2[1];
					if ($kabCode2 == '3300') continue; // skip province level

					if (isset($finalData[$kabCode2])) {
						// Update existing entry
						$finalData[$kabCode2]['ipm'] = (float) $v2;
					} else {
						// Create new entry from Var 2034 data
						$kabSuffix2 = substr($kabCode2, 2);
						$namaKab2 = isset($kabNames2034[$kabCode2]) ? $kabNames2034[$kabCode2] : $kabCode2;
						$finalData[$kabCode2] = [
							'tahun' => $year,
							'kab' => $kabSuffix2,
							'nama_kab' => $namaKab2,
							'ipm' => (float) $v2
						];
					}
					$ipm2Count++;
				}
			}
			ksort($finalData);
			error_log("IPM Kab Sync: Var 2034 (Metode Baru) processed $ipm2Count kab/kota IPM values");
		} else {
			error_log("IPM Kab Sync: Var 2034 data not available for year $year");
			if (empty($finalData)) {
				return $response->withJson(["status" => "error", "message" => "Tidak ada data IPM Kab dari Var 83 maupun Var 2034 untuk tahun $year."], 200);
			}
		}

		// === STEP 3: Calculate GROWTH from previous year ===
		// ipm_growth = IPM tahun ini - IPM tahun lalu (begitu juga untuk ahh, hls, rls, ppp)
		$prevYear = (int) $year - 1;
		foreach ($finalData as $kabCode => &$row) {
			$stmtPrev = $db->prepare("SELECT ipm, ahh, hls, rls, ppp FROM t_ipm_kab WHERE kab = :kab AND tahun = :t");
			$stmtPrev->execute([':kab' => $row['kab'], ':t' => $prevYear]);
			$prevRow = $stmtPrev->fetch();
			if ($prevRow && isset($row['ipm']) && $prevRow['ipm'] > 0) {
				$row['ipm_growth'] = round((float) $row['ipm'] - (float) $prevRow['ipm'], 2);
				$row['ahh_growth'] = isset($row['ahh']) ? round((float) $row['ahh'] - (float) $prevRow['ahh'], 2) : 0;
				$row['hls_growth'] = isset($row['hls']) ? round((float) $row['hls'] - (float) $prevRow['hls'], 2) : 0;
				$row['rls_growth'] = isset($row['rls']) ? round((float) $row['rls'] - (float) $prevRow['rls'], 2) : 0;
				$row['ppp_growth'] = isset($row['ppp']) ? round((float) $row['ppp'] - (float) $prevRow['ppp'], 2) : 0;
			}
		}
		unset($row); // break reference

		$stats = ots_empty_sync_stats();
		foreach ($finalData as $kabCode => $row) {
			$result = ots_sync_upsert_row($db, 't_ipm_kab', $row, ['kab', 'tahun'], ['ipm']);
			ots_add_sync_stat($stats, $result);
		}
		$count = ots_success_count($stats);

		error_log("IPM Kab Sync completed: $count kab/kota for $year");
		return $response->withJson([
			"status" => ots_sync_status($stats),
			"message" => "Sync IPM kab selesai ($count kab/kota).",
			"year" => $year,
			"count" => $count,
			"summary" => $stats
		], empty($stats['errors']) ? 200 : 500);
	});

	// 14. Sync Naker Prov Data: Tarik dari API BPS (Var 98 TPT + Var 63 TPAK) -> Masuk ke t_naker_prov
	$app->get("/run-sync-naker-prov-test", function (Request $request, Response $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;

		error_log("Naker Prov Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		if ($clean == 'true') {
			$deleteResult = ots_delete_rows_by_year($db, 't_naker_prov', $year);
			if ($deleteResult['action'] === 'error') {
				return $response->withJson(["status" => "error", "message" => "Gagal membersihkan data naker provinsi.", "detail" => $deleteResult['message']], 500);
			}
			error_log("Naker Prov Sync: Deleted existing data for year $year");
		}

		// Pastikan AUTO_INCREMENT
		try {
			$db->exec("ALTER TABLE t_naker_prov MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
		} catch (Exception $e) {
		}

		// Fetch APIs
		// Var 98: TPT Semesteran (turvar: 0) - vervar 3300, turtahun 148=Februari, 149=Agustus
		// Var 63: TPAK (turvar: 0) - vervar 3300, turtahun 0=Tahun
		$urls = [
			98 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/98/th/$bpsYear/key/$key",
			63 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/63/th/$bpsYear/key/$key"
		];

		$apiData = [];
		foreach ($urls as $varId => $url) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			$output = curl_exec($ch);
			curl_close($ch);
			$decoded = json_decode($output, true);
			if ($decoded && isset($decoded['datacontent'])) {
				$apiData[$varId] = $decoded;
			}
		}

		if (empty($apiData)) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API belum memiliki data Naker Prov untuk tahun $year.", "count" => 0], 200);
		}

		// Mapping turtahun -> bulan & des_bulan
		$periodMap = [
			148 => ['bulan' => 2, 'des_bulan' => 'Februari'],
			149 => ['bulan' => 8, 'des_bulan' => 'Agustus']
		];

		// Parse data per period
		$rowData = []; // bulan => row

		// 1. Parse Var 98 (TPT Semesteran): key = {provCode}980{bpsYear}{turtahun}
		if (isset($apiData[98])) {
			foreach ($apiData[98]['datacontent'] as $k => $v) {
				if (preg_match('/^3300980' . $bpsYear . '(\d+)$/', $k, $matches)) {
					$turtahun = (int) $matches[1];
					if (!isset($periodMap[$turtahun]))
						continue;
					$bulan = $periodMap[$turtahun]['bulan'];
					if (!isset($rowData[$bulan])) {
						$rowData[$bulan] = [
							'tahun' => $year,
							'bulan' => $bulan,
							'des_bulan' => $periodMap[$turtahun]['des_bulan']
						];
					}
					$rowData[$bulan]['tpt'] = $v;
				}
			}
		}

		// 2. Parse Var 63 (TPAK): key = {provCode}630{bpsYear}{turtahun}
		//    turtahun=0 (Tahun) -> assign to Agustus row (bulan=8)
		if (isset($apiData[63])) {
			foreach ($apiData[63]['datacontent'] as $k => $v) {
				if (preg_match('/^3300630' . $bpsYear . '(\d+)$/', $k, $matches)) {
					$turtahun = (int) $matches[1];
					if ($turtahun !== 0)
						continue;
					// Assign TPAK to Agustus row
					$bulan = 8;
					if (!isset($rowData[$bulan])) {
						$rowData[$bulan] = [
							'tahun' => $year,
							'bulan' => $bulan,
							'des_bulan' => 'Agustus'
						];
					}
					$rowData[$bulan]['tpak'] = $v;
				}
			}
		}

		ksort($rowData);
		$stats = ots_empty_sync_stats();
		$results = [];
		foreach ($rowData as $bulan => $row) {
			$result = ots_sync_upsert_row($db, 't_naker_prov', $row, ['bulan', 'tahun'], ['tpt', 'tpak']);
			ots_add_sync_stat($stats, $result);
			$results[] = $row;
		}
		$count = ots_success_count($stats);

		error_log("Naker Prov Sync completed: $count rows processed for $year");
		return $response->withJson([
			"status" => ots_sync_status($stats),
			"message" => "Sync naker prov selesai ($count rows).",
			"year" => $year,
			"count" => $count,
			"summary" => $stats,
			"data" => $results
		], empty($stats['errors']) ? 200 : 500);
	});

	// 13. Sync Naker Kab Data: Tarik dari API BPS (Var 63 TPAK + Var 64 TPT) -> Masuk ke t_naker_kab
	$app->get("/run-sync-naker-kab-test", function (Request $request, Response $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$clean = $request->getParam('clean');
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;

		error_log("Naker Kab Sync started for Year: $year (BPS Year: $bpsYear), clean: $clean");

		if ($clean == 'true') {
			$deleteResult = ots_delete_rows_by_year($db, 't_naker_kab', $year);
			if ($deleteResult['action'] === 'error') {
				return $response->withJson(["status" => "error", "message" => "Gagal membersihkan data naker kab/kota.", "detail" => $deleteResult['message']], 500);
			}
			error_log("Naker Kab Sync: Deleted existing data for year $year");
		}

		// Pastikan AUTO_INCREMENT
		try {
			$db->exec("ALTER TABLE t_naker_kab MODIFY id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
		} catch (Exception $e) {
		}

		// Fetch APIs
		// Var 63: TPAK (turvar: 0) - vervar kab codes 3301-3376
		// Var 64: TPT (turvar: 0) - vervar kab codes 3301-3376
		$urls = [
			63 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/63/th/$bpsYear/key/$key",
			64 => "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/64/th/$bpsYear/key/$key"
		];

		$apiData = [];
		foreach ($urls as $varId => $url) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			$output = curl_exec($ch);
			curl_close($ch);
			$decoded = json_decode($output, true);
			if ($decoded && isset($decoded['datacontent'])) {
				$apiData[$varId] = $decoded;
			}
		}

		if (empty($apiData)) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API belum memiliki data Naker Kab untuk tahun $year.", "count" => 0], 200);
		}

		// Build kabCode => namaKab from vervar
		$kabCodeToName = [];
		$srcData = isset($apiData[63]) ? $apiData[63] : (isset($apiData[64]) ? $apiData[64] : null);
		if ($srcData && isset($srcData['vervar'])) {
			foreach ($srcData['vervar'] as $vv) {
				$kabCode = (string) $vv['val'];
				$namaKab = preg_replace('/^\d+\s+/', '', $vv['label']);
				$kabCodeToName[$kabCode] = $namaKab;
			}
		}

		$finalData = []; // kabCode => row data

		// 1. Parse Var 63 (TPAK): key = {kabCode}630{bpsYear}{turtahun}
		if (isset($apiData[63])) {
			foreach ($apiData[63]['datacontent'] as $k => $v) {
				if (preg_match('/^(\d{4})630' . $bpsYear . '(\d+)$/', $k, $matches)) {
					$kabCode = $matches[1];
					$turtahun = (int) $matches[2];
					if ($turtahun !== 0)
						continue;
					if ($kabCode == '3300')
						continue;

					if (!isset($finalData[$kabCode])) {
						$kabSuffix = substr($kabCode, 2); // "3301" -> "01"
						$finalData[$kabCode] = [
							'tahun' => $year,
							'bulan' => 8,
							'des_bulan' => 'Agustus',
							'kab' => $kabSuffix,
							'nama_kab' => isset($kabCodeToName[$kabCode]) ? $kabCodeToName[$kabCode] : $kabCode
						];
					}
					$finalData[$kabCode]['tpak'] = $v;
				}
			}
		}

		// 2. Parse Var 64 (TPT): key = {kabCode}640{bpsYear}{turtahun}
		if (isset($apiData[64])) {
			foreach ($apiData[64]['datacontent'] as $k => $v) {
				if (preg_match('/^(\d{4})640' . $bpsYear . '(\d+)$/', $k, $matches)) {
					$kabCode = $matches[1];
					$turtahun = (int) $matches[2];
					if ($turtahun !== 0)
						continue;
					if ($kabCode == '3300')
						continue;

					if (!isset($finalData[$kabCode])) {
						$kabSuffix = substr($kabCode, 2);
						$finalData[$kabCode] = [
							'tahun' => $year,
							'bulan' => 8,
							'des_bulan' => 'Agustus',
							'kab' => $kabSuffix,
							'nama_kab' => isset($kabCodeToName[$kabCode]) ? $kabCodeToName[$kabCode] : $kabCode
						];
					}
					$finalData[$kabCode]['tpt'] = $v;
				}
			}
		}

		// Sort by kab code ascending
		ksort($finalData);

		$stats = ots_empty_sync_stats();
		foreach ($finalData as $kabCode => $row) {
			$result = ots_sync_upsert_row($db, 't_naker_kab', $row, ['kab', 'tahun'], ['tpt', 'tpak']);
			ots_add_sync_stat($stats, $result);
		}
		$count = ots_success_count($stats);

		error_log("Naker Kab Sync completed: $count rows processed for $year");
		return $response->withJson([
			"status" => ots_sync_status($stats),
			"message" => "Sync naker kab selesai ($count kab/kota).",
			"year" => $year,
			"count" => $count,
			"summary" => $stats
		], empty($stats['errors']) ? 200 : 500);
	});

	// 13. Sync Real Data: Tarik dari API BPS -> Masuk ke ots_inflasi
	$app->get("/run-sync-test", function (Request $request, Response $response) {
		// A. Konfigurasi DINAMIS
		// Support ?year=2026 (Default: Tahun Sekarang)
		$year = ots_normalize_year($request->getParam('year') ? $request->getParam('year') : date('Y'));
		$bpsYear = (int) $year - 1900; // 2026 -> 126
		$prevBpsYear = $bpsYear - 1;   // Tahun Sebelumnya -> 125

		// URL GROUP 1: TAHUN INI (Current Year)
		// 1. Inflasi Bulanan (Per Kota) - Var 2411
		$urlMain = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/2411/th/$bpsYear/key/$bpsApiKey";
		// 2. Inflasi Tahunan (YTD) - Var 2387
		$urlTahunan = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/2387/th/$bpsYear/key/$bpsApiKey";
		// 3. Inflasi YoY - Var 2263
		$urlYoy = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/2263/th/$bpsYear/key/$bpsApiKey";

		// URL GROUP 1.5: NASIONAL (Indonesia - Domain 0000)
		// 4. Inflasi Nasional MtM - Var 2262
		$urlNasMonth = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/2262/th/$bpsYear/key/$bpsApiKey";

		// URL GROUP 1.6: KELOMPOK PENGELUARAN (Jateng - Domain 3300)
		// 5. Inflasi Kelompok Pengeluaran - Var 2756
		$urlGroups = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/2756/th/$bpsYear/key/$bpsApiKey";

		// URL GROUP 1.7: KOTA NASIONAL LAINNYA (Domain 0000)
		// 6. IHK Kota-Kota - Var 1
		$urlCities = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/1/th/$bpsYear/key/$bpsApiKey";

		// URL GROUP 1.8: KOTA JATENG YoY (Domain 0000)
		// 7. Inflasi YoY Kota-Kota - Var 2249
		$urlCitiesYoy = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/2249/th/$bpsYear/key/$bpsApiKey";

		// URL GROUP 1.9: KOTA JATENG YTD (Domain 0000)
		// 8. Inflasi YTD Kota-Kota - Var 2388
		$urlCitiesTh = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/2388/th/$bpsYear/key/$bpsApiKey";

		// URL GROUP 2: TAHUN SEBELUMNYA (Previous Year)
		// ... (keep previous URLs)
		$urlMainPrev = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/2411/th/$prevBpsYear/key/$bpsApiKey";
		$urlTahunanPrev = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/2387/th/$prevBpsYear/key/$bpsApiKey";
		$urlYoyPrev = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/2263/th/$prevBpsYear/key/$bpsApiKey";

		$targetTable = "ots_inflasi";

		// Helper Function Fetch API
		$fetchApi = function ($url) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
			$json = curl_exec($ch);
			curl_close($ch);
			return json_decode($json, true);
		};

		// B. Ambil Data
		$dataMain = $fetchApi($urlMain);
		$dataTahunan = $fetchApi($urlTahunan);
		$dataYoy = $fetchApi($urlYoy);
		$dataNasMonth = $fetchApi($urlNasMonth);

		// Ambil Data Kelompok Pengeluaran (Cek tahun ini dulu, kalau kosong fallback ke 124/2024)
		$dataGroups = $fetchApi($urlGroups);
		$groupBpsYear = $bpsYear;
		if (!$dataGroups || !isset($dataGroups['datacontent']) || empty($dataGroups['datacontent'])) {
			$urlGroupsFallback = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/3300/var/2756/th/124/key/$bpsApiKey";
			$dataGroups = $fetchApi($urlGroupsFallback);
			$groupBpsYear = 124; // Gunakan 2024 sebagai fallback
		}

		// Ambil Data Kota-Kota Nasional
		$dataCities = $fetchApi($urlCities);

		// Ambil Data YoY Kota-Kota
		$dataCitiesYoy = $fetchApi($urlCitiesYoy);

		// Ambil Data YTD Kota-Kota
		$dataCitiesTh = $fetchApi($urlCitiesTh);

		$dataMainPrev = $fetchApi($urlMainPrev);
		$dataTahunanPrev = $fetchApi($urlTahunanPrev);
		$dataYoyPrev = $fetchApi($urlYoyPrev);

		if (!$dataMain || !isset($dataMain['datacontent'])) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API Inflasi (Var 2411) belum memiliki data untuk tahun $year.", "count" => 0], 200);
		}

		// C. Parsing Data (Decoder Kode BPS)
		$cityMap = [
			1 => "month",      // Jateng (Anggap ini Inflasi Umum/Rata2)
			2 => "cilacap",
			3 => "purwokerto",
			4 => "wonosobo",
			5 => "wonogiri",
			6 => "rembang",
			7 => "kudus",
			8 => "surakarta",
			9 => "semarang",
			10 => "tegal"
		];

		// Mapping Bulan (Var 2411: 90=Jan) -> DB Format integer
		$monthMap = [
			90 => "1",
			91 => "2",
			92 => "3",
			93 => "4",
			94 => "5",
			95 => "6",
			96 => "7",
			97 => "8",
			98 => "9",
			99 => "10",
			100 => "11",
			101 => "12"
		];

		// Mapping TurVar Kelompok Pengeluaran (Var 2756)
		$groupMap = [
			2474 => "makanan",
			2475 => "pakaian",
			2476 => "perumahan",
			2477 => "perlengkapan",
			2478 => "kesehatan",
			2479 => "transportasi",
			2480 => "informasi",
			2481 => "rekreasi",
			2482 => "pendidikan",
			2483 => "restoran",
			2484 => "lainnya"
		];

		// Mapping Kota Nasional (Var 1)
		$natCityMap = [
			3100 => "dki",
			3673 => "serang",
			3273 => "bandung",
			3471 => "yogyakarta",
			3578 => "surabaya"
		];

		// Mapping YoY Kota JATENG + Nasional (Var 2249)
		$jatengCityYoyMap = [
			53 => "cilacap_yoy",
			54 => "purwokerto_yoy",
			55 => "wonosobo_yoy",
			56 => "wonogiri_yoy",
			57 => "rembang_yoy",
			58 => "kudus_yoy",
			59 => "surakarta_yoy",
			60 => "semarang_yoy",
			61 => "tegal_yoy",
			42 => "dki_yoy",
			79 => "serang_yoy",
			48 => "bandung_yoy",
			63 => "yogyakarta_yoy",
			74 => "surabaya_yoy"
		];

		// Mapping YTD Kota JATENG + Nasional (Var 2388)
		$jatengCityThMap = [
			53 => "cilacap_th",
			54 => "purwokerto_th",
			55 => "wonosobo_th",
			56 => "wonogiri_th",
			57 => "rembang_th",
			58 => "kudus_th",
			59 => "surakarta_th",
			60 => "semarang_th",
			61 => "tegal_th",
			42 => "dki_th",
			79 => "serang_th",
			48 => "bandung_th",
			63 => "yogyakarta_th",
			74 => "surabaya_th"
		];

		$updates = [];

		// --- LOOP TAHUN INI ---
		foreach ($dataMain['datacontent'] as $key => $val) {
			// Parsing Key: {vervar}{2411}{0}{tahun_3digit}{turtahun_2or3digit}
			// Var "2411" (4 chars) + turvar "0" (1 char) = 5 chars fixed middle
			// tahun = 3 chars (e.g. 125)
			// Total fixed middle+tahun = 8 chars ("24110125")
			$len = strlen($key);

			// Extract from right: find tahun position
			// Key format: [vervar_1or2][2411][0][tahun_3][turtahun_2or3]
			// We know "24110" is 5 chars fixed, tahun is 3 chars = 8 chars from after vervar
			// vervar can be 1 or 2 chars, so total length varies

			// Strategy: vervar is everything before "24110"
			$pos2411 = strpos($key, "24110");
			if ($pos2411 === false)
				continue;

			$id_wilayah = substr($key, 0, $pos2411);
			$rest = substr($key, $pos2411 + 5); // after "24110", rest = {tahun_3}{turtahun_2or3}
			$id_tahun = substr($rest, 0, 3);
			$id_bulan = substr($rest, 3); // remaining is turtahun

			// Validasi Mapping
			if (!isset($cityMap[$id_wilayah]))
				continue;
			if (!isset($monthMap[$id_bulan]))
				continue;
			if ($id_tahun != $bpsYear)
				continue;

			$db_tahun = $year;
			$db_bulan = $monthMap[$id_bulan];
			$col_name = $cityMap[$id_wilayah];
			$monthInt = (int) $db_bulan;

			// Init Array Record
			$data_key = $db_tahun . "-" . $db_bulan;
			if (!isset($updates[$data_key])) {
				// Mapping bulan angka ke nama bulan
				$monthNameMap = [
					'1' => 'Januari', '2' => 'Februari', '3' => 'Maret',
					'4' => 'April', '5' => 'Mei', '6' => 'Juni',
					'7' => 'Juli', '8' => 'Agustus', '9' => 'September',
					'10' => 'Oktober', '11' => 'Nopember', '12' => 'Desember'
				];
				$updates[$data_key] = [
					'tahun' => $db_tahun,
					'bulan' => $db_bulan,
					'des_bulan' => isset($monthNameMap[$db_bulan]) ? $monthNameMap[$db_bulan] : '-',
					'month_sebelum' => '-',
					'tahunan_sebelum' => '-',
					'yoy_sebelum' => '-',
					'tahun_sebelum' => (string) ((int) $db_tahun - 1),
					'nas_month' => '-',
					'nas_th' => '-',
					'nas_yoy' => '-',
					'makanan' => '-',
					'pakaian' => '-',
					'perumahan' => '-',
					'perlengkapan' => '-',
					'kesehatan' => '-',
					'transportasi' => '-',
					'informasi' => '-',
					'rekreasi' => '-',
					'pendidikan' => '-',
					'restoran' => '-',
					'lainnya' => '-',
					'dki' => '-',
					'serang' => '-',
					'bandung' => '-',
					'yogyakarta' => '-',
					'surabaya' => '-',
					'cilacap_yoy' => '-',
					'purwokerto_yoy' => '-',
					'wonosobo_yoy' => '-',
					'wonogiri_yoy' => '-',
					'rembang_yoy' => '-',
					'kudus_yoy' => '-',
					'surakarta_yoy' => '-',
					'semarang_yoy' => '-',
					'tegal_yoy' => '-',
					'cilacap_th' => '-',
					'purwokerto_th' => '-',
					'wonosobo_th' => '-',
					'wonogiri_th' => '-',
					'rembang_th' => '-',
					'kudus_th' => '-',
					'surakarta_th' => '-',
					'semarang_th' => '-',
					'tegal_th' => '-',
					'dki_yoy' => '-',
					'serang_yoy' => '-',
					'bandung_yoy' => '-',
					'yogyakarta_yoy' => '-',
					'surabaya_yoy' => '-',
					'dki_th' => '-',
					'serang_th' => '-',
					'bandung_th' => '-',
					'yogyakarta_th' => '-',
					'surabaya_th' => '-'
				];
			}
			$updates[$data_key][$col_name] = $val;

			// --- MERGE CURRENT YEAR (Tahunan & YoY) ---
			// Key Format: 3300 + VarID + 0 + Tahun(126) + Bulan(1-12)
			$keyTahunan = "3300" . "2387" . "0" . $bpsYear . $monthInt;
			if (isset($dataTahunan['datacontent'][$keyTahunan])) {
				$updates[$data_key]['tahunan'] = $dataTahunan['datacontent'][$keyTahunan];
			}

			$keyYoy = "3300" . "2263" . "0" . $bpsYear . $monthInt;
			if (isset($dataYoy['datacontent'][$keyYoy])) {
				$updates[$data_key]['yoy'] = $dataYoy['datacontent'][$keyYoy];
			}

			// --- MERGE NASIONAL (INDONESIA - Domain 0000) ---
			// Key Format: 0000 + VarID + 0 + Tahun(126) + Bulan(1-12)

			// 1. National MtM (Var 2262)
			$keyNasMonth = "9999" . "2262" . "0" . $bpsYear . $monthInt;
			if (isset($dataNasMonth['datacontent'][$keyNasMonth])) {
				$updates[$data_key]['nas_month'] = $dataNasMonth['datacontent'][$keyNasMonth];
			}

			// 2. National YTD (Var 2387)
			$keyNasTh = "9999" . "2387" . "0" . $bpsYear . $monthInt;
			if (isset($dataTahunan['datacontent'][$keyNasTh])) {
				$updates[$data_key]['nas_th'] = $dataTahunan['datacontent'][$keyNasTh];
			}

			// 3. National YoY (Var 2263)
			$keyNasYoy = "9999" . "2263" . "0" . $bpsYear . $monthInt;
			if (isset($dataYoy['datacontent'][$keyNasYoy])) {
				$updates[$data_key]['nas_yoy'] = $dataYoy['datacontent'][$keyNasYoy];
			}

			// --- MERGE KELOMPOK PENGELUARAN (Var 2756) ---
			// Key Format: [Bulan][VarID][TurVarID][Tahun]0
			foreach ($groupMap as $turvarId => $col) {
				$keyGrp = $monthInt . "2756" . $turvarId . $groupBpsYear . "0";
				if (isset($dataGroups['datacontent'][$keyGrp])) {
					$updates[$data_key][$col] = $dataGroups['datacontent'][$keyGrp];
				}
			}

			// --- MERGE KOTA NASIONAL (Var 1) ---
			// Key Format: [CityID][VarID][TurVarID][YearID][MonthID]
			foreach ($natCityMap as $cityCode => $col) {
				$keyCity = $cityCode . "1" . "0" . $bpsYear . $monthInt;
				if (isset($dataCities['datacontent'][$keyCity])) {
					$updates[$data_key][$col] = $dataCities['datacontent'][$keyCity];
				}
			}

			// --- MERGE YoY KOTA JATENG (Var 2249) ---
			// Key Format: [CityID] + [VarID] + [TurVarID] + [TahunID] + [MonthID]
			// Sesuai data: 53224901257 -> Cilacap (53) Var 2249 TurVar 0 Tahun 125 Bulan 7
			foreach ($jatengCityYoyMap as $cityId => $col) {
				$keyCityYoy = $cityId . "2249" . "0" . $bpsYear . $monthInt;
				if (isset($dataCitiesYoy['datacontent'][$keyCityYoy])) {
					$updates[$data_key][$col] = $dataCitiesYoy['datacontent'][$keyCityYoy];
				}
			}

			// --- MERGE YTD KOTA JATENG (Var 2388) ---
			// Key Format: [CityID] + [VarID] + [TurVarID] + [TahunID] + [MonthID]
			foreach ($jatengCityThMap as $cityId => $col) {
				$keyCityTh = $cityId . "2388" . "0" . $bpsYear . $monthInt;
				if (isset($dataCitiesTh['datacontent'][$keyCityTh])) {
					$updates[$data_key][$col] = $dataCitiesTh['datacontent'][$keyCityTh];
				}
			}
		}

		// --- MERGE PREVIOUS YEAR DATA ---
		// Kita loop hasil API Previous Year, lalu cocokkan 'Bulan' nya dengan data yang sudah ada di $updates.
		// Asumsi: Kita hanya update row yang SUDAH ADA di $updates (Tahun Ini).
		// Jika data tahun lalu ada tapi tahun ini belum, tidak kita insert (karena base-nya laporan Tahun Ini).

		if ($dataMainPrev && isset($dataMainPrev['datacontent'])) {
			foreach ($dataMainPrev['datacontent'] as $key => $val) {
				$pos2411 = strpos($key, "24110");
				if ($pos2411 === false)
					continue;

				$id_wilayah = substr($key, 0, $pos2411);
				$rest = substr($key, $pos2411 + 5);
				$id_tahun = substr($rest, 0, 3);
				$id_bulan = substr($rest, 3);

				if (!isset($cityMap[$id_wilayah]))
					continue;
				if (!isset($monthMap[$id_bulan]))
					continue;
				if ($id_tahun != $prevBpsYear)
					continue; // Pastikan data tahun lalu

				// Hanya ambil data wilayah 'Jawa Tengah' (month) untuk kolom 'month_sebelum'
				// Karena request user spesifik: "bagian kolom â€˜month_sebelumâ€™ itu datanya diambil dari url..."
				// Asumsi kolom month_sebelum adalah inflasi umum (Jateng).
				if ($cityMap[$id_wilayah] == 'month') {
					$db_bulan = $monthMap[$id_bulan];
					$data_key = $year . "-" . $db_bulan; // Key pakai Tahun INI

					if (isset($updates[$data_key])) {
						$updates[$data_key]['month_sebelum'] = $val;
					}
				}
			}
		}

		// Merge Tahunan Previous (tahunan_sebelum)
		if ($dataTahunanPrev && isset($dataTahunanPrev['datacontent'])) {
			foreach ($updates as $key => &$row) {
				$monthInt = (int) $row['bulan'];
				$keyTahunanPrev = "3300" . "2387" . "0" . $prevBpsYear . $monthInt;
				if (isset($dataTahunanPrev['datacontent'][$keyTahunanPrev])) {
					$row['tahunan_sebelum'] = $dataTahunanPrev['datacontent'][$keyTahunanPrev];
				}
			}
			unset($row); // PENTING: hapus referensi agar tidak corrupt data
		}

		// Merge YoY Previous (yoy_sebelum)
		if ($dataYoyPrev && isset($dataYoyPrev['datacontent'])) {
			foreach ($updates as $key => &$row) {
				$monthInt = (int) $row['bulan'];
				$keyYoyPrev = "3300" . "2263" . "0" . $prevBpsYear . $monthInt;
				if (isset($dataYoyPrev['datacontent'][$keyYoyPrev])) {
					$row['yoy_sebelum'] = $dataYoyPrev['datacontent'][$keyYoyPrev];
				}
			}
			unset($row); // PENTING: hapus referensi agar tidak corrupt data
		}


		// D. Filter Latest Only (untuk cron job)
		$latest = $request->getParam('latest');
		if ($latest == 'true' && !empty($updates)) {
			// Ambil bulan terbesar (terbaru)
			$latestKey = null;
			$latestBulan = 0;
			foreach ($updates as $key => $row) {
				$bulanInt = (int) $row['bulan'];
				if ($bulanInt > $latestBulan) {
					$latestBulan = $bulanInt;
					$latestKey = $key;
				}
			}
			if ($latestKey) {
				$updates = [$latestKey => $updates[$latestKey]];
			}
		}


		// E. Sort updates by bulan (ascending) - data terbaru di bawah
		uasort($updates, function ($a, $b) {
			return (int) $a['bulan'] - (int) $b['bulan'];
		});

		// F. Eksekusi Database
		$db = $this->db;
		$logs = [];

		// Hapus data lama jika latest=true
		if ($latest == 'true') {
			ots_delete_rows_by_year($db, $targetTable, $year);
			$logs[] = "Deleted old data for year $year (latest mode)";
		}

		// 1. Cek Kolom & Siapkan Default Value
		$stmtCol = $db->query("DESCRIBE $targetTable");
		$columns = $stmtCol->fetchAll(PDO::FETCH_ASSOC);

		$defaultRow = [];
		$hasDesBulan = false;

		foreach ($columns as $col) {
			$field = $col['Field'];
			if ($field == 'id')
				continue;
			if ($field == 'des_bulan')
				$hasDesBulan = true;

			// Default 0 untuk angka, "-" untuk string
			if (strpos($col['Type'], 'int') !== false || strpos($col['Type'], 'decimal') !== false || strpos($col['Type'], 'float') !== false) {
				$defaultRow[$field] = 0;
			} else {
				$defaultRow[$field] = "-";
			}
		}

		// 2. Siapkan ID Manual
		$stmtMax = $db->query("SELECT MAX(id) as max_id FROM $targetTable");
		$rowMax = $stmtMax->fetch();
		$nextId = ($rowMax && $rowMax['max_id']) ? ((int) $rowMax['max_id'] + 1) : 1;

		foreach ($updates as $period => $row) {
			// Cek Existing
			$cekSql = "SELECT id FROM $targetTable WHERE tahun = :t AND bulan = :b";
			$stmt = $db->prepare($cekSql);
			$stmt->execute([':t' => $row['tahun'], ':b' => $row['bulan']]);
			$existing = $stmt->fetch();

			if ($existing) {
				// UPDATE
				$setParts = [];
				$params = [':id' => $existing['id']];
				foreach ($row as $col => $val) {
					if ($col == 'tahun' || $col == 'bulan')
						continue;
					if (!array_key_exists($col, $defaultRow))
						continue;
					$setParts[] = "$col = :$col";
					$params[":$col"] = $val;
				}

				// Update Helper Columns
				$namaBulan = ["01" => "Januari", "02" => "Februari", "03" => "Maret", "04" => "April", "05" => "Mei", "06" => "Juni", "07" => "Juli", "08" => "Agustus", "09" => "September", "10" => "Oktober", "11" => "Nopember", "12" => "Desember"];

				if ($hasDesBulan && isset($namaBulan[$row['bulan']])) {
					$setParts[] = "des_bulan = :des_bulan_upd";
					$params[":des_bulan_upd"] = $namaBulan[$row['bulan']];
				}

				if (!empty($setParts)) {
					$sql = "UPDATE $targetTable SET " . implode(", ", $setParts) . " WHERE id = :id";
					try {
						$db->prepare($sql)->execute($params);
						$logs[] = "Updated $period (ID: {$existing['id']})";
					} catch (Exception $e) {
						$logs[] = "Err Update $period: " . $e->getMessage();
					}
				}
			} else {
				// INSERT
				$insertData = $defaultRow;
				$insertData['id'] = $nextId++;

				// Timpa dengan data API
				foreach ($row as $k => $v) {
					if (array_key_exists($k, $insertData)) {
						$insertData[$k] = $v;
					}
				}

				// Isi nama bulan
				$namaBulan = ["01" => "Januari", "02" => "Februari", "03" => "Maret", "04" => "April", "05" => "Mei", "06" => "Juni", "07" => "Juli", "08" => "Agustus", "09" => "September", "10" => "Oktober", "11" => "Nopember", "12" => "Desember"];

				if ($hasDesBulan && isset($namaBulan[$row['bulan']])) {
					$insertData['des_bulan'] = $namaBulan[$row['bulan']];
				}

				$cols = array_keys($insertData);
				$paramNames = array_map(function ($c) {
					return ":$c";
				}, $cols);

				$sql = "INSERT INTO $targetTable (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $paramNames) . ")";

				$params = [];
				foreach ($insertData as $k => $v)
					$params[":$k"] = $v;

				try {
					$db->prepare($sql)->execute($params);
					$logs[] = "Inserted $period (ID: " . $insertData['id'] . ")";
				} catch (Exception $e) {
					$logs[] = "Failed Insert $period: " . $e->getMessage();
				}
			}
		}

		return $response->withJson([
			"status" => "success",
			"target_table" => $targetTable,
			"processed_data" => $updates,
			"logs" => $logs
		], 200);
	});

	$app->group('', function () use ($app) {

	// =============================================
	// MASTER SYNC: Jalankan Semua Sync Sekaligus
	// =============================================
	$app->get("/run-sync-all", function (Request $request, Response $response) use ($app) {
		set_time_limit(0); // Prevent PHP timeout since this takes > 60s
		$paramYear = $request->getParam('year');
		$year = ots_normalize_year($paramYear ? $paramYear : date('Y'));
		$startTime = microtime(true);

		$syncEndpoints = [
			'ntp' => '/run-sync-ntp-test',
			'ekspor' => '/run-sync-ekspor-test',
			'impor' => '/run-sync-impor-test',
			'neraca' => '/run-sync-neraca-test',
			'miskin_kab' => '/run-sync-miskin-kab-test',
			'miskin_prov' => '/run-sync-miskin-prov-test',
			'giniratio' => '/run-sync-giniratio-test',
			'ipm_prov' => '/run-sync-ipm-prov-test',
			'ipm_kab' => '/run-sync-ipm-kab-test',
			'naker_prov' => '/run-sync-naker-prov-test',
			'naker_kab' => '/run-sync-naker-kab-test',
			'inflasi' => '/run-sync-test'
		];

		$results = [];
		$successCount = 0;
		$errorCount = 0;
		$isFirst = true;

		foreach ($syncEndpoints as $name => $endpoint) {
			// Delay 2 detik antar sync untuk menghindari rate limiting BPS API
			if (!$isFirst) {
				sleep(2);
			}
			$isFirst = false;

			$syncStart = microtime(true);
			$maxRetries = 2; // try up to 2 times
			$lastHttpCode = 0;
			$lastBody = '';

			for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
				try {
					$env = \Slim\Http\Environment::mock([
						'REQUEST_METHOD' => 'GET',
						'REQUEST_URI' => $endpoint,
						'QUERY_STRING' => 'year=' . $year
					]);
					$subReq = \Slim\Http\Request::createFromEnvironment($env);
					$subResp = new \Slim\Http\Response();
					$subResp = $app->process($subReq, $subResp);

					$lastHttpCode = $subResp->getStatusCode();
					$lastBody = (string) $subResp->getBody();
					$decoded = json_decode($lastBody, true);

					if ($lastHttpCode == 200 && $decoded && isset($decoded['status']) && $decoded['status'] == 'success') {
						break; // success, no retry needed
					}

					// If failed and have retries left, wait and retry
					if ($attempt < $maxRetries) {
						error_log("Sync $name attempt $attempt failed (HTTP:$lastHttpCode), retrying in 3s...");
						sleep(3);
					}
				} catch (\Exception $e) {
					$lastBody = json_encode(['status' => 'error', 'message' => $e->getMessage()]);
					if ($attempt < $maxRetries) {
						sleep(3);
					}
				}
			}

			$syncDuration = round(microtime(true) - $syncStart, 2);
			$decoded = json_decode($lastBody, true);

			if ($lastHttpCode == 200 && $decoded && isset($decoded['status']) && $decoded['status'] == 'success') {
				$successCount++;
				$results[$name] = [
					'status' => 'success',
					'duration' => $syncDuration . 's',
					'message' => isset($decoded['message']) ? $decoded['message'] : 'Sync berhasil'
				];
			} else {
				$errorCount++;
				$errorMsg = ($decoded && isset($decoded['message'])) ? $decoded['message'] : "HTTP $lastHttpCode";
				$results[$name] = [
					'status' => 'error',
					'duration' => $syncDuration . 's',
					'message' => $errorMsg
				];
			}
		}

		$totalDuration = round(microtime(true) - $startTime, 2);

		return $response->withJson([
			'status' => ($errorCount == 0) ? 'success' : 'partial',
			'year' => $year,
			'summary' => [
				'total' => count($syncEndpoints),
				'success' => $successCount,
				'error' => $errorCount,
				'duration' => $totalDuration . 's'
			],
			'details' => $results
		], 200);
	});

	// ============================================
	// SYNC NAKER PROV: Fetch TPT from BPS API var/2562
	// ============================================
	$app->get('/run-sync-naker-prov', function ($request, $response) {
		$db = $this->db;
		$year = ots_normalize_year($request->getQueryParam('year', date('Y')));
		$bpsYear = (int) $year - 1900;
		$key = $bpsApiKey;

		error_log("Naker Prov Sync started for Year: $year (BPS Year: $bpsYear)");

		// Fetch TPT (var 2562) from BPS
		$url = "https://webapi.bps.go.id/v1/api/list/model/data/lang/ind/domain/0000/var/2562/th/$bpsYear/key/$key";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$output = curl_exec($ch);
		curl_close($ch);
		$data = json_decode($output, true);

		if (!$data || !isset($data['datacontent']) || empty($data['datacontent'])) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). BPS API belum memiliki data Naker Prov untuk tahun $year.", "count" => 0], 200);
		}

		// Quarter mapping: turtahun → bulan, des_bulan
		$quarterMap = [
			325 => ['bulan' => 2, 'des_bulan' => 'Februari', 'des_en' => 'February'],
			326 => ['bulan' => 5, 'des_bulan' => 'Mei', 'des_en' => 'May'],
			327 => ['bulan' => 8, 'des_bulan' => 'Agustus', 'des_en' => 'August'],
			328 => ['bulan' => 11, 'des_bulan' => 'Nopember', 'des_en' => 'November']
		];

		// Previous quarter for sebelumnya
		$prevQuarter = [
			325 => ['id' => 'Nopember ' . ($year - 1), 'en' => 'November ' . ($year - 1)],
			326 => ['id' => 'Februari ' . $year, 'en' => 'February ' . $year],
			327 => ['id' => 'Mei ' . $year, 'en' => 'May ' . $year],
			328 => ['id' => 'Agustus ' . $year, 'en' => 'August ' . $year]
		];

		// Parse: key pattern = {domain}0{var}0{bpsYear}{turtahun}
		// domain 3300 = Jawa Tengah
		$finalData = [];
		foreach ($data['datacontent'] as $k => $v) {
			if (preg_match('/^330025620' . $bpsYear . '(\d{3})$/', $k, $matches)) {
				$tt = (int) $matches[1];
				if (isset($quarterMap[$tt])) {
					$q = $quarterMap[$tt];
					$finalData[$q['bulan']] = [
						'bulan' => $q['bulan'],
						'des_bulan' => $q['des_bulan'],
						'tahun' => $year,
						'tpt' => $v
					];
				}
			}
		}

		if (empty($finalData)) {
			return $response->withJson(["status" => "success", "message" => "Sync selesai (0 data). Tidak ada data TPT Jawa Tengah untuk tahun $year.", "count" => 0], 200);
		}

		// Calculate tanda/sign/poin/sebelumnya for each quarter
		foreach ($finalData as $bulan => &$row) {
			// Find previous quarter TPT from DB
			$prevBulan = null;
			$prevTahun = $year;
			if ($bulan == 2) {
				$prevBulan = 11;
				$prevTahun = $year - 1;
			} elseif ($bulan == 5) {
				$prevBulan = 2;
			} elseif ($bulan == 8) {
				$prevBulan = 5;
			} elseif ($bulan == 11) {
				$prevBulan = 8;
			}

			$prevTpt = null;
			if ($prevBulan) {
				$stPrev = $db->prepare("SELECT tpt FROM t_naker_prov WHERE bulan=:b AND tahun=:t");
				$stPrev->execute([':b' => $prevBulan, ':t' => $prevTahun]);
				$pr = $stPrev->fetch();
				if ($pr && (float) $pr['tpt'] > 0)
					$prevTpt = (float) $pr['tpt'];
			}

			// Also check if it's in current sync batch
			if ($prevTpt === null && $prevBulan && isset($finalData[$prevBulan])) {
				$prevTpt = (float) $finalData[$prevBulan]['tpt'];
			}

			if ($prevTpt !== null) {
				$poin = round((float) $row['tpt'] - $prevTpt, 2);
				$row['tanda'] = ($poin >= 0) ? 'naik' : 'turun';
				$row['sign'] = ($poin >= 0) ? 'increased' : 'decreased';
				$row['poin'] = $poin;
			}

			// sebelumnya / month_before
			foreach ($quarterMap as $tt => $qm) {
				if ($qm['bulan'] == $bulan && isset($prevQuarter[$tt])) {
					$row['sebelumnya'] = $prevQuarter[$tt]['id'];
					$row['month_before'] = $prevQuarter[$tt]['en'];
				}
			}
		}
		unset($row);

		// Upsert
		$stats = ots_empty_sync_stats();
		foreach ($finalData as $bulan => $row) {
			$result = ots_sync_upsert_row($db, 't_naker_prov', $row, ['bulan', 'tahun'], ['tpt']);
			ots_add_sync_stat($stats, $result);
		}
		$count = ots_success_count($stats);

		return $response->withJson([
			"status" => ots_sync_status($stats),
			"message" => "Naker Prov: $count baris TPT berhasil disinkronkan.",
			"year" => $year,
			"count" => $count,
			"summary" => $stats,
			"data" => array_values($finalData)
		], empty($stats['errors']) ? 200 : 500);
	});

	// ============================================
	// SYNC IMPLEMENTATION (INTERNAL ROUTES)
	// ============================================
	
	// ============================================
	// PREVIEW-SYNC: Fetch BPS data for review (no DB write)
	// ============================================
	$app->get('/preview-sync', function ($request, $response) {
		$db = $this->db;
		$table = $request->getQueryParam('table', '');
		$year = ots_normalize_year($request->getQueryParam('year', date('Y')));

		if (!$table) {
			return $response->withJson(["status" => "error", "message" => "Parameter 'table' wajib diisi."], 400);
		}

		try {
			$table = ots_assert_allowed_table($table);
			$quotedTable = ots_quote_identifier($table);
		} catch (Exception $e) {
			return $response->withJson(["status" => "error", "message" => $e->getMessage()], 400);
		}

		// Get columns from DB
		try {
			$colStmt = $db->query("SHOW COLUMNS FROM $quotedTable");
			$columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			return $response->withJson(["status" => "error", "message" => "Tabel '$table' tidak ditemukan."], 404);
		}

		// Fetch existing data from DB for this year
		$existingData = [];
		try {
			if (in_array('tahun', $columns) && in_array('bulan', $columns)) {
				$stmt = $db->prepare("SELECT * FROM $quotedTable WHERE " . ots_quote_identifier('tahun') . " = :tahun ORDER BY CAST(" . ots_quote_identifier('bulan') . " AS UNSIGNED)");
				$stmt->execute([':tahun' => $year]);
				$existingData = $stmt->fetchAll(PDO::FETCH_ASSOC);
			} elseif (in_array('tahun', $columns)) {
				$stmt = $db->prepare("SELECT * FROM $quotedTable WHERE " . ots_quote_identifier('tahun') . " = :tahun");
				$stmt->execute([':tahun' => $year]);
				$existingData = $stmt->fetchAll(PDO::FETCH_ASSOC);
			}
		} catch (Exception $e) {
		}

		// If we have existing data, return it for review
		if (!empty($existingData)) {
			return $response->withJson([
				"status" => "success",
				"source" => "database",
				"message" => "Data dari database untuk tahun $year.",
				"columns" => $columns,
				"data" => $existingData
			], 200);
		}

		// No existing data, return empty template
		$templateRows = [];
		$monthNames = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'Nopember', 12 => 'Desember'];

		if (in_array('bulan', $columns)) {
			// Monthly data - create 12 rows
			for ($m = 1; $m <= 12; $m++) {
				$row = [];
				foreach ($columns as $col) {
					if ($col === 'id')
						$row[$col] = '';
					elseif ($col === 'bulan')
						$row[$col] = $m;
					elseif ($col === 'des_bulan')
						$row[$col] = $monthNames[$m];
					elseif ($col === 'tahun')
						$row[$col] = $year;
					else
						$row[$col] = '';
				}
				$templateRows[] = $row;
			}
		} elseif (in_array('tahun', $columns) && in_array('kab', $columns)) {
			// Kab data - create 35 rows
			$kabList = [
				['01', 'Kab. Cilacap'],
				['02', 'Kab. Banyumas'],
				['03', 'Kab. Purbalingga'],
				['04', 'Kab. Banjarnegara'],
				['05', 'Kab. Kebumen'],
				['06', 'Kab. Purworejo'],
				['07', 'Kab. Wonosobo'],
				['08', 'Kab. Magelang'],
				['09', 'Kab. Boyolali'],
				['10', 'Kab. Klaten'],
				['11', 'Kab. Sukoharjo'],
				['12', 'Kab. Wonogiri'],
				['13', 'Kab. Karanganyar'],
				['14', 'Kab. Sragen'],
				['15', 'Kab. Grobogan'],
				['16', 'Kab. Blora'],
				['17', 'Kab. Rembang'],
				['18', 'Kab. Pati'],
				['19', 'Kab. Kudus'],
				['20', 'Kab. Jepara'],
				['21', 'Kab. Demak'],
				['22', 'Kab. Semarang'],
				['23', 'Kab. Temanggung'],
				['24', 'Kab. Kendal'],
				['25', 'Kab. Batang'],
				['26', 'Kab. Pekalongan'],
				['27', 'Kab. Pemalang'],
				['28', 'Kab. Tegal'],
				['29', 'Kab. Brebes'],
				['71', 'Kota Magelang'],
				['72', 'Kota Surakarta'],
				['73', 'Kota Salatiga'],
				['74', 'Kota Semarang'],
				['75', 'Kota Pekalongan'],
				['76', 'Kota Tegal']
			];
			foreach ($kabList as $k) {
				$row = [];
				foreach ($columns as $col) {
					if ($col === 'id')
						$row[$col] = '';
					elseif ($col === 'kab')
						$row[$col] = $k[0];
					elseif ($col === 'nama_kab')
						$row[$col] = $k[1];
					elseif ($col === 'tahun')
						$row[$col] = $year;
					else
						$row[$col] = '';
				}
				$templateRows[] = $row;
			}
		} else {
			// Single row (e.g. ipm_prov)
			$row = [];
			foreach ($columns as $col) {
				if ($col === 'id')
					$row[$col] = '';
				elseif ($col === 'tahun')
					$row[$col] = $year;
				else
					$row[$col] = '';
			}
			$templateRows[] = $row;
		}

		return $response->withJson([
			"status" => "success",
			"source" => "template",
			"message" => "Tidak ada data di database. Template kosong untuk diisi manual.",
			"columns" => $columns,
			"data" => $templateRows
		], 200);
	});

	// ============================================
	// SAVE-SYNC: Save reviewed data to database
	// ============================================
	$app->post('/save-sync', function ($request, $response) {
		$db = $this->db;
		$body = $request->getParsedBody();

		if (!$body) {
			$body = json_decode($request->getBody()->getContents(), true);
		}

		$table = isset($body['table']) ? $body['table'] : '';
		$year = isset($body['year']) ? ots_normalize_year($body['year']) : '';
		$data = isset($body['data']) ? $body['data'] : [];

		if (!$table || empty($data)) {
			return $response->withJson(["status" => "error", "message" => "Parameter 'table' dan 'data' wajib diisi."], 400);
		}

		try {
			$table = ots_assert_allowed_table($table);
			$quotedTable = ots_quote_identifier($table);
			$columnMap = ots_column_map($db, $table);
		} catch (Exception $e) {
			return $response->withJson(["status" => "error", "message" => $e->getMessage()], 400);
		}

		// Validate table exists
		try {
			$db->query("SHOW COLUMNS FROM $quotedTable");
		} catch (Exception $e) {
			return $response->withJson(["status" => "error", "message" => "Tabel '$table' tidak ditemukan."], 404);
		}

		$inserted = 0;
		$updated = 0;
		$errors = [];

		foreach ($data as $idx => $row) {
			try {
				// Determine unique key for upsert
				$hasId = isset($row['id']) && $row['id'] !== '' && $row['id'] > 0;

				if ($hasId) {
					// UPDATE existing row
					$set = [];
					$params = ['id' => $row['id']];
					foreach ($row as $col => $val) {
						if ($col === 'id' || !isset($columnMap[$col]))
							continue;
						$set[] = ots_quote_identifier($col) . "=:$col";
						$params[$col] = $val;
					}
					if (!empty($set)) {
						$sql = "UPDATE $quotedTable SET " . implode(", ", $set) . " WHERE " . ots_quote_identifier('id') . "=:id";
						$db->prepare($sql)->execute($params);
						$updated++;
					}
				} else {
					// Check if row exists by unique keys
					$exists = false;
					$existId = null;

					if (isset($row['bulan']) && isset($row['tahun'])) {
						$stmt = $db->prepare("SELECT " . ots_quote_identifier('id') . " FROM $quotedTable WHERE " . ots_quote_identifier('bulan') . "=:bulan AND " . ots_quote_identifier('tahun') . "=:tahun");
						$stmt->execute(['bulan' => $row['bulan'], 'tahun' => $row['tahun']]);
						$found = $stmt->fetch();
						if ($found) {
							$exists = true;
							$existId = $found['id'];
						}
					} elseif (isset($row['kab']) && isset($row['tahun'])) {
						$stmt = $db->prepare("SELECT " . ots_quote_identifier('id') . " FROM $quotedTable WHERE " . ots_quote_identifier('kab') . "=:kab AND " . ots_quote_identifier('tahun') . "=:tahun");
						$stmt->execute(['kab' => $row['kab'], 'tahun' => $row['tahun']]);
						$found = $stmt->fetch();
						if ($found) {
							$exists = true;
							$existId = $found['id'];
						}
					} elseif (isset($row['tahun'])) {
						$stmt = $db->prepare("SELECT " . ots_quote_identifier('id') . " FROM $quotedTable WHERE " . ots_quote_identifier('tahun') . "=:tahun LIMIT 1");
						$stmt->execute(['tahun' => $row['tahun']]);
						$found = $stmt->fetch();
						if ($found) {
							$exists = true;
							$existId = $found['id'];
						}
					}

					if ($exists) {
						$set = [];
						$params = ['id' => $existId];
						foreach ($row as $col => $val) {
							if ($col === 'id' || !isset($columnMap[$col]))
								continue;
							$set[] = ots_quote_identifier($col) . "=:$col";
							$params[$col] = $val;
						}
						if (!empty($set)) {
							$sql = "UPDATE $quotedTable SET " . implode(", ", $set) . " WHERE " . ots_quote_identifier('id') . "=:id";
							$db->prepare($sql)->execute($params);
							$updated++;
						}
					} else {
						$insertRow = $row;
						unset($insertRow['id']);
						foreach (array_keys($insertRow) as $col) {
							if (!isset($columnMap[$col])) {
								unset($insertRow[$col]);
							}
						}
						$fullInsertRow = ots_default_row($db, $table);
						foreach ($insertRow as $col => $val) {
							$fullInsertRow[$col] = $val;
						}
						$cols = array_keys($fullInsertRow);
						$phs = array_map(function ($c) {
							return ":$c";
						}, $cols);
						$sql = "INSERT INTO $quotedTable (" . implode(", ", array_map(function ($c) {
							return ots_quote_identifier($c);
						}, $cols)) . ") VALUES (" . implode(", ", $phs) . ")";
						$db->prepare($sql)->execute($fullInsertRow);
						$inserted++;
					}
				}
			} catch (Exception $e) {
				$errors[] = "Row " . ($idx + 1) . ": " . $e->getMessage();
			}
		}

		$msg = "Berhasil! $updated data diupdate, $inserted data baru ditambahkan.";
		if (!empty($errors)) {
			$msg .= " " . count($errors) . " error.";
		}

		return $response->withJson([
			"status" => "success",
			"message" => $msg,
			"updated" => $updated,
			"inserted" => $inserted,
			"errors" => $errors
		], 200);
	});

	})->add($container['authMiddleware']);

};

