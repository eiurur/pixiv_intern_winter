<?php

require_once 'limonade/lib/limonade.php';

function configure() {
  option('base_uri', '/');
  option('session', 'isu4_qualifier_session');
  $host = getenv('ISU4_DB_HOST') ?: 'localhost';
  $port = getenv('ISU4_DB_PORT') ?: 3306;
  $dbname = getenv('ISU4_DB_NAME') ?: 'isu4_qualifier';
  $username = getenv('ISU4_DB_USER') ?: 'root';
  $password = getenv('ISU4_DB_PASSWORD');
  $db = null;
  try {
    $db = new PDO(
      'mysql:host=' . $host . ';port=' . $port. ';dbname=' . $dbname,
      $username,
      $password,
      [ PDO::ATTR_PERSISTENT => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET `utf8`',
      ]
    );
  } catch (PDOException $e) {
    halt("Connection faild: $e");
  }
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  option('db_conn', $db);
  $config = [
    'user_lock_threshold' => getenv('ISU4_USER_LOCK_THRESHOLD') ?: 3,
    'ip_ban_threshold' => getenv('ISU4_IP_BAN_THRESHOLD') ?: 10
  ];
  option('config', $config);
}

function uri_for($path) {
  $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?: $_SERVER['HTTP_HOST'];
  return 'http://' . $host . $path;
}

function get($key) {
  return set($key);
}

function before() {
  layout('base.html.php');
}

function calculate_password_hash($password, $salt) {
  return hash('sha256', $password . ':' . $salt);
}

function login_log($succeeded, $login, $user_id=null) {
  $db = option('db_conn');

  $stmt = $db->prepare('INSERT INTO login_log (`created_at`, `user_id`, `login`, `ip`, `succeeded`) VALUES (NOW(),:user_id,:login,:ip,:succeeded)');
  $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
  $stmt->bindValue(':login', $login, PDO::PARAM_STR);
  $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
  $stmt->bindValue(':succeeded', $succeeded ? 1 : 0, PDO::PARAM_INT);
  $stmt->execute();
}

function lock_log($user_id) {
  $db = option('db_conn');

  $stmt = $db->prepare('INSERT INTO user_locks (`user_id`, `num`) VALUES (:user_id, 1) ON DUPLICATE KEY UPDATE num = num + 1');
  $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
  $stmt->execute();
}

function ban_log() {
  $db = option('db_conn');

  $stmt = $db->prepare('INSERT INTO ip_bans (`ip`, `num`) VALUES (:ip, 1) ON DUPLICATE KEY UPDATE num = num + 1');
  $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
  $stmt->execute();
}

function login_time_log($user_id) {
  $db = option('db_conn');

  $stmt = $db->prepare('INSERT INTO login_time_log (`created_at`, `user_id`, `ip`) VALUES (now(), :user_id, :ip)');
  $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
  $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
  $stmt->execute();
}

function clear_failures($user_id) {
  $db = option('db_conn');

  $stmt = $db->prepare('UPDATE ip_bans SET num = 0 WHERE ip = :ip');
  $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
  $stmt->execute();

  $stmt = $db->prepare('UPDATE user_locks SET num = 0 WHERE user_id = :user_id');
  $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
  $stmt->execute();
}

function user_locked($user) {
  if (empty($user)) { return null; }

  $db = option('db_conn');

  $stmt = $db->prepare('SELECT num AS failures FROM user_locks WHERE user_id = :user_id');
  $stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
  $stmt->execute();
  $log = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($log['failures'] === null) {
    return false;
  }

  $config = option('config');
  return $config['user_lock_threshold'] <= $log['failures'];
}

function ip_banned() {
  $db = option('db_conn');

  $stmt = $db->prepare('SELECT num AS failures FROM ip_bans WHERE ip = :ip');
  $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
  $stmt->execute();
  $log = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($log['failures'] === null) {
    return false;
  }

  $config = option('config');
  return $config['ip_ban_threshold'] <= $log['failures'];
}

function attempt_login($login, $password) {
  $db = option('db_conn');

  $stmt = $db->prepare('SELECT * FROM users WHERE login = :login');
  $stmt->bindValue(':login', $login, PDO::PARAM_STR);
  $stmt->execute();
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (ip_banned()) {
    login_log(false, $login, isset($user['id']) ? $user['id'] : null);
    if (!empty($user)) {
      lock_log($user['id']);
    }
    return ['error' => 'banned'];
  }

  if (user_locked($user)) {
    login_log(false, $login, $user['id']);
    ban_log();
    return ['error' => 'locked'];
  }

  if (!empty($user) && calculate_password_hash($password, $user['salt']) === $user['password_hash']) {
    login_log(true, $login, $user['id']);
    login_time_log($user['id']);
    clear_failures($user['id']);
    return ['user' => $user];
  }
  elseif (!empty($user)) {
    login_log(false, $login, $user['id']);
    lock_log($user['id']);
    ban_log();
    return ['error' => 'wrong_password'];
  }
  else {
    login_log(false, $login);
    ban_log();
    return ['error' => 'wrong_login'];
  }
}

function current_user() {
  if (empty($_SESSION['user_id'])) {
    return null;
  }

  $db = option('db_conn');

  $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
  $stmt->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_INT);
  $stmt->execute();
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (empty($user)) {
    unset($_SESSION['user_id']);
    return null;
  }

  return $user;
}

function last_login($user) {
  if (empty($user)) {
    return null;
  }

  $db = option('db_conn');

  $stmt = $db->prepare('SELECT ip, created_at FROM login_time_log WHERE user_id = :id ORDER BY id DESC LIMIT 2');
  $stmt->bindValue(':id', $user['id'], PDO::PARAM_INT);
  $stmt->execute();
  $stmt->fetch();

  return $stmt->fetch(PDO::FETCH_ASSOC);
}

function banned_ips() {
  $threshold = option('config')['ip_ban_threshold'];
  $ips = [];
  
  $db = option('db_conn');
  $stmt = $db->prepare('SELECT ip FROM (SELECT ip, MAX(succeeded) as max_succeeded, COUNT(1) as cnt FROM login_log GROUP BY ip) AS t0 WHERE t0.max_succeeded = 0 AND t0.cnt >= :threshold');
  $stmt->bindValue(':threshold', $threshold, PDO::PARAM_INT);
  $stmt->execute();
  $not_succeeded = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
  $ips = array_merge($not_succeeded);
  
  $stmt = $db->prepare('SELECT ip, MAX(id) AS last_login_id FROM login_log WHERE succeeded = 1 GROUP by ip');
  $stmt->execute();
  $last_succeeds = $stmt->fetchAll();
  
  foreach ($last_succeeds as $row) {
    $stmt = $db->prepare('SELECT COUNT(1) AS cnt FROM login_log WHERE ip = :ip AND :id < id');
    $stmt->bindValue(':ip', $row['ip'], PDO::PARAM_STR);
    $stmt->bindValue(':id', $row['last_login_id'], PDO::PARAM_INT);
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    if ($threshold <= $count) {
      array_push($ips, $row['ip']);
    }
  }

  return $ips;
}

function locked_users() {
  $threshold = option('config')['user_lock_threshold'];
  $user_ids = [];
  
  $db = option('db_conn');
  $stmt = $db->prepare('SELECT login FROM (SELECT user_id, login, MAX(succeeded) as max_succeeded, COUNT(1) as cnt FROM login_log GROUP BY user_id) AS t0 WHERE t0.user_id IS NOT NULL AND t0.max_succeeded = 0 AND t0.cnt >= :threshold');
  $stmt->bindValue(':threshold', $threshold, PDO::PARAM_INT);
  $stmt->execute();
  $not_succeeded = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
  $user_ids = array_merge($not_succeeded);
  
  $stmt = $db->prepare('SELECT user_id, login, MAX(id) AS last_login_id FROM login_log WHERE user_id IS NOT NULL AND succeeded = 1 GROUP BY user_id');
  $stmt->execute();
  $last_succeeds = $stmt->fetchAll();
  
  foreach ($last_succeeds as $row) {
    $stmt = $db->prepare('SELECT COUNT(1) AS cnt FROM login_log WHERE user_id = :user_id AND :id < id');
    $stmt->bindValue(':user_id', $row['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':id', $row['last_login_id'], PDO::PARAM_INT);
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    if ($threshold <= $count) {
      array_push($user_ids, $row['login']);
    }
  }
  
  return $user_ids;
}

dispatch_get('/', function() {
  return html('index.html.php');
});

dispatch_post('/login', function() {
  $result = attempt_login($_POST['login'], $_POST['password']);
  if (!empty($result['user'])) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $result['user']['id'];
    return redirect_to('/mypage');
  }
  else {
    switch($result['error']) {
      case 'locked':
        flash('notice', 'This account is locked.');
        break;
      case 'banned':
        flash('notice', "You're banned.");
        break;
      default:
        flash('notice', 'Wrong username or password');
        break;
    }
    return redirect_to('/');
  }
});

dispatch_get('/mypage', function() {
  $user = current_user();
  if (empty($user)) {
    flash('notice', 'You must be logged in');
    return redirect_to('/');
  }
  else {
    set('user', $user);
    set('last_login', last_login($user));
    return html('mypage.html.php');
  }
});

dispatch_get('/report', function() {
  return json_encode([
    'banned_ips' => banned_ips(),
    'locked_users' => locked_users()
  ]);
});
run();
