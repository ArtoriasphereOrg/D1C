<?php
define('CF_ACCOUNT_ID', '');
define('CF_DATABASE_ID', '');
define('CF_API_TOKEN', '');
define('BASE_URL', 'https://api.cloudflare.com/client/v4/accounts/'.CF_ACCOUNT_ID.'/d1/database/'.CF_DATABASE_ID);
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }
    $body = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? '';
    switch ($action) {
        case 'list_tables':
            echo json_encode(callD1("SELECT name, sql FROM sqlite_master WHERE type='table' ORDER BY name"));
            exit;
        case 'table_info':
            $t = $body['table'] ?? '';
            if (!$t) respond(400, false, 'table required');
            $info = callD1("PRAGMA table_info(".escId($t).")");
            $count = callD1("SELECT COUNT(*) as cnt FROM ".escId($t));
            $idx = callD1("PRAGMA index_list(".escId($t).")");
            echo json_encode(['success'=>true,'info'=>$info,'count'=>$count,'indexes'=>$idx]);
            exit;
        case 'create_table':
            $t = $body['table'] ?? '';
            $cols = $body['columns'] ?? [];
            if (!$t || empty($cols)) respond(400, false, 'table and columns required');
            $defs = [];
            foreach ($cols as $c) {
                $def = escId($c['name']).' '.($c['type'] ?? 'TEXT');
                if (!empty($c['pk'])) $def .= ' PRIMARY KEY';
                if (!empty($c['ai'])) $def .= ' AUTOINCREMENT';
                if (!empty($c['notnull'])) $def .= ' NOT NULL';
                if (isset($c['default']) && $c['default'] !== '') $def .= " DEFAULT ".(is_numeric($c['default']) ? $c['default'] : "'".$c['default']."'");
                $defs[] = $def;
            }
            $sql = "CREATE TABLE IF NOT EXISTS ".escId($t)." (".implode(', ', $defs).")";
            echo json_encode(callD1($sql));
            exit;
        case 'drop_table':
            $t = $body['table'] ?? '';
            if (!$t) respond(400, false, 'table required');
            echo json_encode(callD1("DROP TABLE IF EXISTS ".escId($t)));
            exit;
        case 'rename_table':
            $t = $body['table'] ?? ''; $n = $body['new_name'] ?? '';
            if (!$t || !$n) respond(400, false, 'table and new_name required');
            echo json_encode(callD1("ALTER TABLE ".escId($t)." RENAME TO ".escId($n)));
            exit;
        case 'truncate_table':
            $t = $body['table'] ?? '';
            if (!$t) respond(400, false, 'table required');
            echo json_encode(callD1("DELETE FROM ".escId($t)));
            exit;
        case 'add_column':
            $t = $body['table'] ?? ''; $c = $body['column'] ?? [];
            if (!$t || empty($c['name'])) respond(400, false, 'table and column.name required');
            $def = escId($c['name']).' '.($c['type'] ?? 'TEXT');
            if (!empty($c['notnull'])) $def .= ' NOT NULL';
            if (isset($c['default']) && $c['default'] !== '') $def .= " DEFAULT ".(is_numeric($c['default']) ? $c['default'] : "'".$c['default']."'");
            echo json_encode(callD1("ALTER TABLE ".escId($t)." ADD COLUMN ".$def));
            exit;
        case 'create_index':
            $t = $body['table'] ?? ''; $cols = $body['columns'] ?? []; $name = $body['name'] ?? ''; $unique = !empty($body['unique']);
            if (!$t || empty($cols)) respond(400, false, 'table and columns required');
            if (!$name) $name = 'idx_'.$t.'_'.implode('_',$cols);
            $sql = ($unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX').' IF NOT EXISTS '.escId($name).' ON '.escId($t).' ('.implode(', ', array_map('escId',$cols)).')';
            echo json_encode(callD1($sql));
            exit;
        case 'drop_index':
            $name = $body['name'] ?? '';
            if (!$name) respond(400, false, 'name required');
            echo json_encode(callD1("DROP INDEX IF EXISTS ".escId($name)));
            exit;
        case 'insert':
            $t = $body['table'] ?? ''; $cols = $body['columns'] ?? []; $vals = $body['values'] ?? [];
            if (!$t || empty($cols) || empty($vals)) respond(400, false, 'table, columns, values required');
            $sql = "INSERT INTO ".escId($t)." (".implode(', ', array_map('escId',$cols)).") VALUES (".implode(', ', array_map('quoteVal',$vals)).")";
            echo json_encode(callD1($sql));
            exit;
        case 'select':
            $t = $body['table'] ?? ''; $where = $body['where'] ?? ''; $limit = (int)($body['limit'] ?? 100); $order = $body['order'] ?? '';
            if (!$t) respond(400, false, 'table required');
            $sql = "SELECT * FROM ".escId($t);
            if ($where) $sql .= " WHERE ".$where;
            if ($order) $sql .= " ORDER BY ".$order;
            $sql .= " LIMIT ".$limit;
            echo json_encode(callD1($sql));
            exit;
        case 'update':
            $t = $body['table'] ?? ''; $set = $body['set'] ?? []; $where = $body['where'] ?? '';
            if (!$t || empty($set)) respond(400, false, 'table and set required');
            if (!$where) respond(400, false, 'where required for update');
            $pairs = [];
            foreach ($set as $col => $val) $pairs[] = escId($col).' = '.quoteVal($val);
            echo json_encode(callD1("UPDATE ".escId($t)." SET ".implode(', ',$pairs)." WHERE ".$where));
            exit;
        case 'delete':
            $t = $body['table'] ?? ''; $where = $body['where'] ?? '';
            if (!$t) respond(400, false, 'table required');
            if (!$where) respond(400, false, 'where required');
            echo json_encode(callD1("DELETE FROM ".escId($t)." WHERE ".$where));
            exit;
        case 'raw':
            $sql = $body['sql'] ?? '';
            if (!$sql) respond(400, false, 'sql required');
            echo json_encode(callD1($sql));
            exit;
        default:
            respond(400, false, "Unknown action: $action");
    }
}
function callD1(string $sql): array {
    $ch = curl_init(BASE_URL.'/query');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['sql' => $sql]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.CF_API_TOKEN],
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err) return ['success'=>false,'error'=>'cURL: '.$err];
    $data = json_decode($raw, true);
    return ['success'=>$data['success']??false,'http_status'=>$code,'sql'=>$sql,'result'=>$data['result']??null,'errors'=>$data['errors']??[],'messages'=>$data['messages']??[]];
}
function respond(int $s, bool $ok, string $msg): void { http_response_code($s); echo json_encode(['success'=>$ok,'error'=>$msg]); exit; }
function escId(string $n): string { return '`'.str_replace('`','``',trim($n)).'`'; }
function quoteVal($v): string {
    if (is_null($v)) return 'NULL';
    if (is_bool($v)) return $v?'1':'0';
    if (is_numeric($v)) return (string)$v;
    return "'".str_replace("'","''",$v)."'";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>D1C Artoriasphere</title>
<link rel="icon" type="image/png" href="https://raw.githubusercontent.com/ArtoriasphereOrg/.github/refs/heads/main/134393625.png">
<style id="base-style">
:root{
  --bg:#07080f;--s1:#0d0f1a;--s2:#12152200;--s3:#1a1e30;
  --b1:#252840;--b2:#2e3252;
  --acc:#7c6fff;--acc2:#a59fff;--acc-dim:#7c6fff18;--acc3:#c4beff;
  --tx:#e8eaf6;--mu:#6e7299;--mu2:#404570;
  --gr:#4ade80;--gr-bg:#061a0e;
  --rd:#f87171;--rd-bg:#1f0808;
  --bl:#60a5fa;--bl-bg:#06112a;
  --am:#fbbf24;--am-bg:#1f1400;
  --pu:#c084fc;--pu-bg:#160a2a;
  --te:#2dd4bf;--te-bg:#041a18;
  --r:10px;--rs:6px;
  --mono:'Fira Code','Cascadia Code','Consolas',monospace;
  --logo-img:url('https://raw.githubusercontent.com/ArtoriasphereOrg/.github/refs/heads/main/134393625.png');
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--tx);font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;height:100vh;display:flex;flex-direction:column;overflow:hidden}

/* ── Custom Checkbox ── */
.cx-wrap{display:inline-flex;align-items:center;gap:6px;cursor:pointer;user-select:none;font-size:12px;color:var(--mu)}
.cx-wrap:hover .cx-box{border-color:var(--acc)}
.cx-wrap input[type=checkbox]{position:absolute;opacity:0;width:0;height:0;pointer-events:none}
.cx-box{width:15px;height:15px;border-radius:3px;border:1.5px solid var(--b2);background:var(--s3);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .12s,border-color .12s}
.cx-box svg{display:none;width:9px;height:9px}
.cx-wrap input:checked + .cx-box{background:var(--acc);border-color:var(--acc)}
.cx-wrap input:checked + .cx-box svg{display:block}

/* ── Custom Select ── */
.csel-wrap{position:relative;display:inline-block;width:100%}
.csel-wrap select{appearance:none;-webkit-appearance:none;background:var(--s3);border:1px solid var(--b2);border-radius:var(--rs);padding:7px 30px 7px 10px;color:var(--tx);font-family:var(--mono);font-size:12px;width:100%;transition:.15s;cursor:pointer}
.csel-wrap select:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-dim)}
.csel-wrap select:hover{border-color:var(--acc2)}
.csel-arrow{pointer-events:none;position:absolute;right:9px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--mu)}
.csel-arrow svg{width:14px;height:14px;display:block}

/* ── Gloader ── */
#gloader{
  position:fixed;inset:0;z-index:9000;background:rgba(7,8,15,.7);
  backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;
  opacity:0;visibility:hidden;pointer-events:none;transition:opacity .2s,visibility .2s;
}
#gloader.show{opacity:1;visibility:visible;pointer-events:all}
#gloader-canvas{border-radius:16px}
.gloader-text{font-size:13px;color:var(--mu);font-family:var(--mono);letter-spacing:.08em}
.gloader-spin{width:36px;height:36px;border:2px solid var(--b2);border-top-color:var(--acc);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.gloader-bar-wrap{width:160px;height:2px;background:var(--b2);border-radius:2px;overflow:hidden}
.gloader-bar{height:100%;width:30%;background:linear-gradient(90deg,var(--acc),var(--acc2));border-radius:2px;animation:barscan 1.1s ease-in-out infinite}
@keyframes barscan{0%{margin-left:0;width:30%}50%{margin-left:50%;width:40%}100%{margin-left:130%;width:30%}}

/* ── Splash ── */
#splash{
  position:fixed;inset:0;background:var(--bg);z-index:9999;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;
  transition:opacity .6s ease,visibility .6s ease;
}
#splash.hidden{opacity:0;visibility:hidden;pointer-events:none}
#splash-canvas{border-radius:20px;display:block}
.splash-title{font-size:28px;font-weight:800;letter-spacing:.04em;color:var(--tx);text-align:center}
.splash-title span{background:linear-gradient(135deg,var(--acc),var(--acc2),#ff8aff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.splash-bar-wrap{width:240px;height:3px;background:var(--b1);border-radius:2px;overflow:hidden}
.splash-bar{height:100%;width:0%;background:linear-gradient(90deg,var(--acc),var(--acc2));border-radius:2px;transition:width .35s ease}
#splash-msg{font-size:11px;color:var(--mu2);font-family:var(--mono);height:16px;text-align:center}

/* ── Topbar ── */
.topbar{background:var(--s1);border-bottom:1px solid var(--b1);padding:0 14px;display:flex;align-items:center;gap:8px;height:52px;flex-shrink:0}
.logo{display:flex;align-items:center;gap:10px;text-decoration:none}
#logo-canvas{border-radius:10px;display:block;cursor:pointer}
.logo-text{display:flex;flex-direction:column;line-height:1.2}
.logo-name{font-size:14px;font-weight:800;letter-spacing:.04em;color:var(--tx)}
.logo-sub{font-size:9px;font-weight:500;color:var(--mu);letter-spacing:.12em;text-transform:uppercase}
.topnav{display:flex;gap:2px;margin-left:8px}
.nb{padding:6px 13px;border-radius:var(--rs);border:none;background:none;color:var(--mu);font-size:12px;font-weight:500;cursor:pointer;transition:.15s;display:flex;align-items:center;gap:5px}
.nb:hover{background:var(--s3);color:var(--tx)}
.nb.on{background:var(--acc-dim);color:var(--acc2);border:1px solid var(--acc)28}
.nb svg{width:13px;height:13px;opacity:.7}
.nb.on svg{opacity:1}
.db-pill{margin-left:auto;font-size:10px;font-family:var(--mono);background:var(--s3);border:1px solid var(--b2);padding:3px 10px;border-radius:20px;color:var(--mu);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.btn-topbar{padding:5px 12px;border-radius:var(--rs);border:1px solid var(--b2);background:none;color:var(--mu);font-size:12px;cursor:pointer;transition:.15s;margin-left:6px;white-space:nowrap}
.btn-topbar:hover{background:var(--s3);color:var(--tx)}

/* ── App Layout ── */
.app{display:flex;flex:1;overflow:hidden}
.sb{width:215px;background:var(--s1);border-right:1px solid var(--b1);display:flex;flex-direction:column;flex-shrink:0;overflow:hidden}
.sb-head{padding:10px 12px;border-bottom:1px solid var(--b1);display:flex;align-items:center;justify-content:space-between;font-size:10px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:1px}
.ib{background:none;border:none;color:var(--mu);cursor:pointer;padding:3px 6px;border-radius:4px;font-size:15px;line-height:1}
.ib:hover{background:var(--s3);color:var(--tx)}
.tl{flex:1;overflow-y:auto;padding:5px}
.tl::-webkit-scrollbar{width:4px}.tl::-webkit-scrollbar-thumb{background:var(--b2);border-radius:2px}
.ti{display:flex;align-items:center;gap:7px;padding:7px 10px;border-radius:var(--rs);cursor:pointer;font-size:12px;color:var(--mu);transition:.12s}
.ti:hover{background:var(--s3);color:var(--tx)}
.ti.on{background:var(--acc-dim);color:var(--acc2);border-left:2px solid var(--acc)}
.ti-n{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500}
.ti-c{font-size:10px;color:var(--mu2);font-family:var(--mono)}
.ti-dot{width:5px;height:5px;border-radius:50%;background:var(--mu2);flex-shrink:0}
.ti.on .ti-dot{background:var(--acc);box-shadow:0 0 6px var(--acc)}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.tbar{background:var(--s1);border-bottom:1px solid var(--b1);display:flex;padding:0 16px;flex-shrink:0;overflow-x:auto}
.tbar::-webkit-scrollbar{display:none}
.tab{padding:12px 16px;font-size:12px;font-weight:600;color:var(--mu);cursor:pointer;border-bottom:2px solid transparent;transition:.15s;white-space:nowrap;background:none;border-left:none;border-right:none;border-top:none;letter-spacing:.03em}
.tab:hover{color:var(--tx)}
.tab.on{color:var(--acc2);border-bottom-color:var(--acc)}
.panels{flex:1;overflow:hidden}
.pn{display:none;height:100%;overflow-y:auto;padding:18px}
.pn::-webkit-scrollbar{width:5px}.pn::-webkit-scrollbar-thumb{background:var(--b2);border-radius:3px}
.pn.on{display:block}

/* ── Form Elements ── */
label{font-size:10px;color:var(--mu);display:block;margin-bottom:4px;font-weight:700;text-transform:uppercase;letter-spacing:.7px}
input[type=text],input[type=number],textarea{
  background:var(--s3);border:1px solid var(--b2);border-radius:var(--rs);
  padding:7px 10px;color:var(--tx);font-family:var(--mono);font-size:12px;width:100%;transition:.15s;resize:vertical}
input:focus,textarea:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-dim)}
textarea{min-height:90px}
.f{display:flex;flex-direction:column;gap:5px}
.fr{display:flex;gap:10px;flex-wrap:wrap}
.fr>.f{flex:1;min-width:130px}

/* ── Buttons ── */
.btn{padding:7px 15px;border-radius:var(--rs);border:1px solid var(--b2);font-size:12px;font-weight:700;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:5px;letter-spacing:.02em}
.btn:active{transform:scale(.97)}
.btn-p{background:var(--acc);color:#fff;border-color:var(--acc);box-shadow:0 0 16px var(--acc)30}
.btn-p:hover{background:#6a5de8;box-shadow:0 0 22px var(--acc)50}
.btn-ok{background:var(--gr-bg);color:var(--gr);border-color:#1a3d22}
.btn-ok:hover{background:#0a2012}
.btn-info{background:var(--bl-bg);color:var(--bl);border-color:#1a2f50}
.btn-info:hover{background:#040d1f}
.btn-warn{background:var(--am-bg);color:var(--am);border-color:#3a2800}
.btn-warn:hover{background:#120c00}
.btn-d{background:var(--rd-bg);color:var(--rd);border-color:#3a1515}
.btn-d:hover{background:#120404}
.btn-g{background:none;color:var(--mu);border-color:var(--b1)}
.btn-g:hover{color:var(--tx);border-color:var(--b2)}
.btn-pu{background:var(--pu-bg);color:var(--pu);border-color:#3a1a5a}
.btn-pu:hover{background:#0d061a}
.btn-sm{padding:4px 11px;font-size:11px}

/* ── Cards ── */
.card{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);padding:16px 18px;margin-bottom:15px}
.card-glow{box-shadow:0 0 30px var(--acc)08}
.ct{font-size:13px;font-weight:700;margin-bottom:13px;display:flex;align-items:center;gap:7px;color:var(--tx)}

/* ── Table ── */
.tw{overflow-x:auto;border-radius:var(--rs);border:1px solid var(--b1)}
.tw::-webkit-scrollbar{height:4px}.tw::-webkit-scrollbar-thumb{background:var(--b2);border-radius:2px}
table{width:100%;border-collapse:collapse;font-size:12px}
th{background:var(--s3);color:var(--mu);font-weight:700;padding:8px 12px;text-align:left;border-bottom:1px solid var(--b1);font-size:10px;letter-spacing:.6px;text-transform:uppercase;white-space:nowrap}
td{padding:7px 12px;border-bottom:1px solid var(--b1);color:var(--tx);font-family:var(--mono);font-size:12px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--s3)80}
.nv{color:var(--mu2);font-style:italic}

/* ── Column Builder Row ── */
.cb{display:flex;flex-direction:column;gap:6px}
.cr{display:flex;gap:7px;align-items:center;background:var(--bg);border:1px solid var(--b1);border-radius:var(--rs);padding:7px 9px}
.cr input{flex:1;min-width:0;background:var(--s3);border:1px solid var(--b2);border-radius:4px;padding:5px 7px;color:var(--tx);font-size:12px;font-family:var(--mono)}
.cr input:focus{outline:none;border-color:var(--acc)}
.xb{background:none;border:none;color:var(--mu2);cursor:pointer;font-size:18px;padding:0 3px;flex-shrink:0;line-height:1}
.xb:hover{color:var(--rd)}

/* ── Log ── */
.lw{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r)}
.le{border-bottom:1px solid var(--b1)}
.le:last-child{border-bottom:none}
.lh{display:flex;align-items:center;gap:9px;padding:10px 14px;cursor:pointer}
.lh:hover{background:var(--s3)60}
.lt{font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;flex-shrink:0;letter-spacing:.04em}
.t-ddl{background:var(--pu-bg);color:var(--pu)}
.t-dml{background:var(--te-bg);color:var(--te)}
.t-sel{background:var(--bl-bg);color:var(--bl)}
.t-del{background:var(--rd-bg);color:var(--rd)}
.t-raw{background:var(--s3);color:var(--acc2)}
.ld{flex:1;font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--tx)}
.lok{color:var(--gr);font-size:11px;font-weight:700}
.ler{color:var(--rd);font-size:11px;font-weight:700}
.lb{display:none;padding:12px 14px;border-top:1px solid var(--b1);flex-direction:column;gap:9px}
.lb.on{display:flex}
.ls{font-family:var(--mono);font-size:11px;color:var(--acc2);background:var(--bg);padding:8px 12px;border-radius:var(--rs);border-left:3px solid var(--acc);word-break:break-all}
.lj{font-family:var(--mono);font-size:11px;background:var(--bg);padding:9px 12px;border-radius:var(--rs);max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-all;line-height:1.6}

/* ── Status bar ── */
.sbar{background:var(--s1);border-top:1px solid var(--b1);padding:5px 14px;display:flex;align-items:center;gap:8px;font-size:11px;color:var(--mu);flex-shrink:0}
.dot{width:7px;height:7px;border-radius:50%;background:var(--mu2);flex-shrink:0;transition:.3s}
.dot.ok{background:var(--gr);box-shadow:0 0 8px var(--gr)60}
.dot.err{background:var(--rd);box-shadow:0 0 8px var(--rd)60}
.dot.sp{background:var(--am);animation:pl 1s infinite;box-shadow:0 0 8px var(--am)60}
@keyframes pl{0%,100%{opacity:1}50%{opacity:.2}}

/* ── Misc ── */
.wb{background:var(--am-bg);border:1px solid #3a2500;border-radius:var(--rs);padding:9px 12px;font-size:12px;color:var(--am)}
.sb2{display:inline-block;font-family:var(--mono);font-size:10px;padding:2px 6px;border-radius:4px;background:var(--s3);border:1px solid var(--b2);color:var(--mu)}
.pk-b{background:var(--am-bg);color:var(--am);border-color:#3a2500}
.div{height:1px;background:var(--b1);margin:14px 0}
.stl{font-size:10px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.8px;margin-bottom:11px}
.es{text-align:center;padding:60px 20px;color:var(--mu2)}
.es .bi{font-size:42px;margin-bottom:14px;opacity:.4}
.kv{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;font-size:12px}
.kv .k{color:var(--mu);font-weight:600}.kv .v{font-family:var(--mono);color:var(--tx)}
.fg{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start}

/* ── Modal ── */
#modal-overlay{
  position:fixed;inset:0;background:rgba(5,6,12,.75);z-index:8888;
  display:flex;align-items:center;justify-content:center;
  opacity:0;visibility:hidden;transition:opacity .2s,visibility .2s;
  backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
}
#modal-overlay.show{opacity:1;visibility:visible}
.modal-box{
  background:var(--s1);border:1px solid var(--b2);border-radius:14px;
  width:430px;max-width:90vw;overflow:hidden;
  transform:scale(.93) translateY(12px);transition:transform .22s cubic-bezier(.34,1.56,.64,1);
  box-shadow:0 32px 80px rgba(0,0,0,.7),0 0 0 1px var(--b1);
}
#modal-overlay.show .modal-box{transform:scale(1) translateY(0)}
.modal-head{display:flex;align-items:center;gap:10px;padding:17px 20px 14px;border-bottom:1px solid var(--b1)}
.modal-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.modal-icon.info{background:var(--bl-bg);color:var(--bl)}
.modal-icon.warn{background:var(--am-bg);color:var(--am)}
.modal-icon.danger{background:var(--rd-bg);color:var(--rd)}
.modal-icon.success{background:var(--gr-bg);color:var(--gr)}
.modal-title{font-size:14px;font-weight:700;color:var(--tx)}
.modal-body{padding:16px 20px;font-size:13px;color:var(--mu);line-height:1.7}
.modal-body strong{color:var(--tx);font-weight:700}
.modal-input{width:100%;background:var(--s3);border:1px solid var(--b2);border-radius:var(--rs);padding:8px 12px;color:var(--tx);font-family:var(--mono);font-size:13px;margin-top:10px;transition:.15s}
.modal-input:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-dim)}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 20px 16px;border-top:1px solid var(--b1)}
.modal-cancel{padding:7px 16px;border-radius:var(--rs);border:1px solid var(--b1);background:none;color:var(--mu);font-size:13px;font-weight:600;cursor:pointer;transition:.15s}
.modal-cancel:hover{background:var(--s3);color:var(--tx)}
.modal-confirm{padding:7px 16px;border-radius:var(--rs);border:none;font-size:13px;font-weight:700;cursor:pointer;transition:.15s}
.modal-confirm:active{transform:scale(.97)}
.modal-confirm.info{background:var(--bl);color:#fff}
.modal-confirm.warn{background:var(--am);color:#1a1000}
.modal-confirm.danger{background:var(--rd);color:#fff}
.modal-confirm.success{background:var(--gr);color:#001a0a}

/* ── Studio ── */
.studio-wrap{display:grid;grid-template-columns:1fr 1fr;gap:14px;height:calc(100% - 36px)}
.studio-editor{display:flex;flex-direction:column;gap:10px}
.studio-tabs{display:flex;gap:4px;margin-bottom:4px}
.studio-tab{padding:5px 12px;border-radius:var(--rs);border:1px solid var(--b1);background:none;color:var(--mu);font-size:11px;font-weight:700;cursor:pointer;transition:.15s}
.studio-tab:hover{background:var(--s3);color:var(--tx)}
.studio-tab.on{background:var(--acc-dim);color:var(--acc2);border-color:var(--acc)40}
.studio-preview{background:var(--bg);border:1px solid var(--b1);border-radius:var(--r);overflow:hidden;display:flex;flex-direction:column}
.studio-preview-bar{background:var(--s1);border-bottom:1px solid var(--b1);padding:8px 12px;font-size:11px;color:var(--mu);display:flex;align-items:center;justify-content:space-between}
.studio-preview-frame{flex:1;padding:14px;overflow-y:auto}
.code-area{font-family:var(--mono);font-size:12px;min-height:320px;tab-size:2;line-height:1.6}
.preset-grid{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px}
.preset-btn{padding:5px 12px;border-radius:20px;border:1px solid var(--b2);background:none;color:var(--mu);font-size:11px;font-weight:600;cursor:pointer;transition:.15s}
.preset-btn:hover{background:var(--s3);color:var(--tx);border-color:var(--acc)50}
</style>
<style id="custom-style"></style>
</head>
<body>
<div id="gloader">
  <canvas id="gloader-canvas" width="80" height="80"></canvas>
  <div class="gloader-bar-wrap"><div class="gloader-bar"></div></div>
  <div class="gloader-text" id="gloader-msg">Executing query...</div>
</div>
<div id="splash">
  <canvas id="splash-canvas" width="120" height="120"></canvas>
  <div>
    <div class="splash-title">D1C <span>Artoriasphere</span></div>
  </div>
  <div class="splash-bar-wrap"><div class="splash-bar" id="sbar-prog"></div></div>
  <div id="splash-msg"></div>
</div>
<div id="modal-overlay">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-icon" id="modal-icon">
        <svg id="modal-icon-svg" viewBox="0 0 16 16" fill="currentColor" width="18" height="18"><path d="M8 1a7 7 0 100 14A7 7 0 008 1zm0 11a1 1 0 110-2 1 1 0 010 2zm1-4H7V5h2v3z"/></svg>
      </div>
      <div class="modal-title" id="modal-title">Confirm</div>
    </div>
    <div class="modal-body" id="modal-body"></div>
    <div class="modal-foot">
      <button class="modal-cancel" id="modal-cancel">Cancel</button>
      <button class="modal-confirm" id="modal-confirm">OK</button>
    </div>
  </div>
</div>
<div class="topbar">
  <div class="logo">
    <canvas id="logo-canvas" width="38" height="38" title="D1C Artoriasphere"></canvas>
    <div class="logo-text">
      <span class="logo-name">D1C Artoriasphere</span>
      <span class="logo-sub">Cloudflare D1</span>
    </div>
  </div>
  <nav class="topnav">
    <button class="nb on" onclick="showPage('browser')">
      <svg viewBox="0 0 16 16" fill="currentColor"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>
      Browser
    </button>
    <button class="nb" onclick="showPage('tables')">
      <svg viewBox="0 0 16 16" fill="currentColor"><path d="M1 3h14v2H1zm0 4h14v2H1zm0 4h14v2H1z"/></svg>
      Tables
    </button>
    <button class="nb" onclick="showPage('crud')">
      <svg viewBox="0 0 16 16" fill="currentColor"><path d="M11 2l3 3-7 7-4 1 1-4 7-7z"/></svg>
      CRUD
    </button>
    <button class="nb" onclick="showPage('raw')">
      <svg viewBox="0 0 16 16" fill="currentColor"><path d="M4 4l-3 4 3 4M12 4l3 4-3 4M9 2l-2 12"/></svg>
      SQL
    </button>
    <button class="nb" onclick="showPage('log')">
      <svg viewBox="0 0 16 16" fill="currentColor"><rect x="2" y="2" width="12" height="2" rx="1"/><rect x="2" y="7" width="8" height="2" rx="1"/><rect x="2" y="12" width="10" height="2" rx="1"/></svg>
      Log
    </button>
    <button class="nb" onclick="showPage('studio')">
      <svg viewBox="0 0 16 16" fill="currentColor"><path d="M2 12l4-4 3 3 3-5 4 6H2z"/><circle cx="12" cy="4" r="2"/></svg>
      Studio
    </button>
  </nav>
  <div class="db-pill"><?= CF_DATABASE_ID ?></div>
  <button class="btn-topbar" onclick="loadTables()">
    <svg style="width:12px;height:12px;vertical-align:-2px" viewBox="0 0 16 16" fill="currentColor"><path d="M14 8A6 6 0 112.1 5.3L4 7V2H1l2 2A8 8 0 1014 8z"/></svg>
    Refresh
  </button>
</div>
<div class="app">
  <aside class="sb">
    <div class="sb-head">
      Tables
      <button class="ib" onclick="loadTables()" title="Refresh">
        <svg style="width:13px;height:13px;vertical-align:-2px" viewBox="0 0 16 16" fill="currentColor"><path d="M14 8A6 6 0 112.1 5.3L4 7V2H1l2 2A8 8 0 1014 8z"/></svg>
      </button>
    </div>
    <div class="tl" id="tl"><div style="padding:24px 12px;text-align:center;color:var(--mu2);font-size:12px">Loading...</div></div>
  </aside>
  <main class="main">
    <div class="tbar" id="tbar"></div>
    <div class="panels" id="panels"></div>
    <div class="sbar">
      <div class="dot" id="dot"></div>
      <span id="stx">Ready</span>
      <span style="margin-left:auto;color:var(--mu2);font-family:var(--mono);font-size:10px;max-width:55%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" id="ssql"></span>
    </div>
  </main>
</div>

<script>
/* ─── SVG helpers for modal icons ─── */
const MODAL_ICONS = {
  info:'<path d="M8 1a7 7 0 100 14A7 7 0 008 1zm0 11a1 1 0 110-2 1 1 0 010 2zm1-4H7V5h2v3z"/>',
  warn:'<path d="M8 1L1 14h14L8 1zm0 9a1 1 0 110 2 1 1 0 010-2zm-1-5h2v4H7V5z"/>',
  danger:'<path d="M3 3l10 10M13 3L3 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
  success:'<path d="M2 8l4 4 8-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>'
};

/* ─── Custom checkbox builder ─── */
function mkCx(name, label, checked=false, cls='') {
  return `<label class="cx-wrap${cls?' '+cls:''}">
    <input type="checkbox" name="${name}"${checked?' checked':''}>
    <span class="cx-box"><svg viewBox="0 0 10 10" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5.5l2 2 4-4"/></svg></span>
    <span>${label}</span>
  </label>`;
}

/* ─── Custom select builder ─── */
function mkSel(id, options, selected='', attrs='') {
  const ARROW_SVG = `<svg class="csel-arrow" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6l4 4 4-4"/></svg>`;
  const opts = options.map(o => {
    const v = typeof o === 'string' ? o : o.value;
    const l = typeof o === 'string' ? o : (o.label || o.value);
    return `<option value="${esc(v)}"${v===selected?' selected':''}>${esc(l)}</option>`;
  }).join('');
  return `<div class="csel-wrap"><select id="${id}" ${attrs}>${opts}</select>${ARROW_SVG}</div>`;
}

const LOGO_URL = 'https://raw.githubusercontent.com/ArtoriasphereOrg/.github/refs/heads/main/134393625.png';
let logoImg = null;
const logoImgEl = new Image();
logoImgEl.crossOrigin = 'anonymous';
logoImgEl.onload = () => { logoImg = logoImgEl; };
logoImgEl.onerror = () => { logoImg = null; };
logoImgEl.src = LOGO_URL;
class RingSystem {
  constructor(n=12) {
    this.particles = Array.from({length:n},(_,i)=>({
      angle: (i/n)*Math.PI*2,
      speed: 0.008 + Math.random()*0.006,
      r: 0.38 + Math.random()*0.08,
      size: 1.2 + Math.random()*1.8,
      phase: Math.random()*Math.PI*2,
      dir: Math.random()>.5?1:-1
    }));
  }
  update(dt) { this.particles.forEach(p=>{ p.angle += p.speed*p.dir; }); }
  draw(ctx, cx, cy, radius, t) {
    this.particles.forEach(p=>{
      const pr = radius * p.r;
      const x = cx + Math.cos(p.angle)*pr;
      const y = cy + Math.sin(p.angle)*pr;
      const alpha = 0.3 + 0.5*Math.abs(Math.sin(t*1.2+p.phase));
      ctx.beginPath();
      ctx.arc(x,y,p.size,0,Math.PI*2);
      ctx.fillStyle = `rgba(168,152,255,${alpha})`;
      ctx.fill();
    });
  }
}
const splashRings = new RingSystem(18);
const logoRings = new RingSystem(8);
function drawLogoOnCanvas(ctx, w, h, t, rings, small) {
  ctx.clearRect(0,0,w,h);
  const cx = w/2, cy = h/2;
  const r = small ? w*0.42 : w*0.4;
  const bgG = ctx.createRadialGradient(cx,cy,0,cx,cy,w*.6);
  bgG.addColorStop(0,'#1a1535');
  bgG.addColorStop(1,'#07080f');
  roundRectFill(ctx,0,0,w,h,small?10:20,bgG);
  const pulse = 0.7 + 0.3*Math.sin(t*1.8);
  ctx.beginPath();
  ctx.arc(cx,cy,r*1.15,0,Math.PI*2);
  ctx.strokeStyle = `rgba(124,111,255,${0.12*pulse})`;
  ctx.lineWidth = small ? 6 : 14;
  ctx.stroke();
  ctx.beginPath();
  ctx.arc(cx,cy,r*1.05,0,Math.PI*2);
  ctx.strokeStyle = `rgba(165,159,255,${0.18*pulse})`;
  ctx.lineWidth = small ? 2 : 4;
  ctx.stroke();
  ctx.save();
  ctx.translate(cx,cy);
  ctx.rotate(t*0.6);
  const arcG = ctx.createLinearGradient(-r,0,r,0);
  arcG.addColorStop(0,'rgba(124,111,255,0)');
  arcG.addColorStop(0.5,'rgba(165,159,255,0.8)');
  arcG.addColorStop(1,'rgba(124,111,255,0)');
  ctx.beginPath();
  ctx.arc(0,0,r*1.0,0,Math.PI*1.2);
  ctx.strokeStyle = arcG;
  ctx.lineWidth = small ? 1.5 : 3;
  ctx.stroke();
  ctx.restore();
  ctx.save();
  ctx.translate(cx,cy);
  ctx.rotate(-t*0.4+Math.PI);
  ctx.beginPath();
  ctx.arc(0,0,r*0.88,0,Math.PI*0.8);
  ctx.strokeStyle = `rgba(196,190,255,${0.35})`;
  ctx.lineWidth = small ? 1 : 2;
  ctx.stroke();
  ctx.restore();
  rings.update(0.016);
  rings.draw(ctx, cx, cy, r, t);
  if(logoImg) {
    ctx.save();
    ctx.beginPath();
    ctx.arc(cx,cy,r*0.78,0,Math.PI*2);
    ctx.clip();
    const ir = r*0.78*2;
    const scale = 1 + 0.04*Math.sin(t*0.9);
    ctx.translate(cx,cy);
    ctx.scale(scale,scale);
    ctx.drawImage(logoImg,-ir/2,-ir/2,ir,ir);
    ctx.restore();
    const vig = ctx.createRadialGradient(cx,cy,r*.3,cx,cy,r*.78);
    vig.addColorStop(0,'rgba(7,8,15,0)');
    vig.addColorStop(1,'rgba(7,8,15,0.45)');
    ctx.beginPath();
    ctx.arc(cx,cy,r*0.78,0,Math.PI*2);
    ctx.fillStyle = vig;
    ctx.fill();
  } else {
    ctx.save();
    ctx.translate(cx,cy);
    const textPulse = 0.9 + 0.1*Math.sin(t*1.5);
    ctx.scale(textPulse,textPulse);
    ctx.fillStyle = '#c4beff';
    ctx.font = `bold ${small?16:38}px 'Segoe UI',sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.shadowColor = 'rgba(124,111,255,0.8)';
    ctx.shadowBlur = small?10:22;
    ctx.fillText('D1C',0,0);
    ctx.restore();
  }
  const nSpark = small ? 4 : 8;
  for(let i=0;i<nSpark;i++){
    const a = (i/nSpark)*Math.PI*2 + t*0.35;
    const dist = r*(1.18 + 0.06*Math.sin(t*2.1+i));
    const px = cx + Math.cos(a)*dist;
    const py = cy + Math.sin(a)*dist;
    const sa = 0.2 + 0.6*Math.abs(Math.sin(t*1.8+i*1.7));
    ctx.beginPath();
    ctx.arc(px,py,small?1:2,0,Math.PI*2);
    ctx.fillStyle = `rgba(196,190,255,${sa})`;
    ctx.fill();
  }
}
function roundRectFill(ctx,x,y,w,h,r,fill){
  ctx.beginPath();
  ctx.moveTo(x+r,y);ctx.lineTo(x+w-r,y);ctx.arcTo(x+w,y,x+w,y+r,r);
  ctx.lineTo(x+w,y+h-r);ctx.arcTo(x+w,y+h,x+w-r,y+h,r);
  ctx.lineTo(x+r,y+h);ctx.arcTo(x,y+h,x,y+h-r,r);
  ctx.lineTo(x,y+r);ctx.arcTo(x,y,x+r,y,r);ctx.closePath();
  ctx.fillStyle=fill;ctx.fill();
}
let splashT = 0, splashRAF = null;
(function(){
  const c = document.getElementById('splash-canvas');
  const ctx = c.getContext('2d');
  function frame(){
    drawLogoOnCanvas(ctx,120,120,splashT,splashRings,false);
    splashT += 0.025;
    splashRAF = requestAnimationFrame(frame);
  }
  frame();
})();
let logoT = 0;
(function(){
  const c = document.getElementById('logo-canvas');
  const ctx = c.getContext('2d');
  function frame(){
    drawLogoOnCanvas(ctx,38,38,logoT,logoRings,true);
    logoT += 0.022;
    requestAnimationFrame(frame);
  }
  frame();
})();
let gloaderT = 0;
(function(){
  const c = document.getElementById('gloader-canvas');
  const ctx = c.getContext('2d');
  const gr = new RingSystem(10);
  function frame(){
    drawLogoOnCanvas(ctx,80,80,gloaderT,gr,false);
    gloaderT += 0.03;
    requestAnimationFrame(frame);
  }
  frame();
})();
const splashMsgs = ['Ready!'];
let splashIdx = 0;
function splashStep(){
  if(splashIdx >= splashMsgs.length) return;
  document.getElementById('splash-msg').textContent = splashMsgs[splashIdx];
  document.getElementById('sbar-prog').style.width = ((splashIdx+1)/splashMsgs.length*100)+'%';
  splashIdx++;
}
splashStep();
const _modal = {
  overlay:null, _res:null,
  init(){ this.overlay = document.getElementById('modal-overlay'); },
  _show(opts){
    return new Promise(res=>{
      this._res = res;
      const iconEl = document.getElementById('modal-icon');
      iconEl.className = 'modal-icon '+(opts.type||'info');
      const t = opts.type||'info';
      iconEl.innerHTML = `<svg viewBox="0 0 16 16" fill="currentColor" width="18" height="18">${MODAL_ICONS[t]||MODAL_ICONS.info}</svg>`;
      document.getElementById('modal-title').textContent = opts.title||'Notice';
      document.getElementById('modal-body').innerHTML = opts.body||'';
      const inp = opts.input != null;
      let inpEl = document.getElementById('_modal-inp');
      if(inp){
        if(!inpEl){
          inpEl = document.createElement('input');
          inpEl.id = '_modal-inp'; inpEl.className = 'modal-input';
          document.getElementById('modal-body').appendChild(inpEl);
        }
        inpEl.value = opts.input||''; inpEl.placeholder = opts.placeholder||''; inpEl.style.display='block';
      } else if(inpEl){ inpEl.style.display='none'; }
      const cf = document.getElementById('modal-confirm');
      const cc = document.getElementById('modal-cancel');
      cf.textContent = opts.ok||'OK';
      cf.className = 'modal-confirm '+(opts.type||'info');
      cc.style.display = opts.cancel===false?'none':'';
      cc.textContent = opts.cancelText||'Cancel';
      this.overlay.classList.add('show');
      if(inp) setTimeout(()=>inpEl.focus(),100);
    });
  },
  _close(val){ this.overlay.classList.remove('show'); if(this._res) this._res(val); this._res=null; },
  alert(msg,opts={}){ return this._show({title:opts.title||'Notice',body:msg,icon:opts.icon||'info',type:opts.type||'info',ok:'OK',cancel:false,...opts}); },
  confirm(msg,opts={}){ return this._show({title:opts.title||'Confirm',body:msg,icon:opts.icon||'warn',type:opts.type||'warn',ok:opts.ok||'Confirm',cancelText:'Cancel',...opts}); },
  prompt(msg,def='',opts={}){ return this._show({title:opts.title||'Input',body:msg,icon:opts.icon||'info',type:opts.type||'info',input:def,placeholder:opts.placeholder||'',ok:'OK',cancelText:'Cancel',...opts}); }
};
function showLoader(msg='Executing query...'){
  document.getElementById('gloader-msg').textContent = msg;
  document.getElementById('gloader').classList.add('show');
}
function hideLoader(){
  document.getElementById('gloader').classList.remove('show');
}
const PROXY = '<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?api=1';
let tables=[], activeTable=null, page='browser', log=[];
const ACTION_MSGS = {
  list_tables:'Fetching table list...',
  table_info:'Loading table schema...',
  create_table:'Creating table...',
  drop_table:'Dropping table...',
  rename_table:'Renaming table...',
  truncate_table:'Truncating table...',
  add_column:'Adding column...',
  create_index:'Creating index...',
  drop_index:'Dropping index...',
  insert:'Inserting row...',
  select:'Fetching rows...',
  update:'Updating rows...',
  delete:'Deleting rows...',
  raw:'Executing SQL...'
};
async function api(body){
  const msg = ACTION_MSGS[body.action] || 'Working...';
  showLoader(msg);
  setStatus('sp', 'Running...','');
  try{
    const r = await fetch(PROXY,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const d = await r.json();
    setStatus(d.success?'ok':'err', d.success?'OK':'Error', d.sql||'');
    log.unshift({action:body.action, data:d, time:new Date().toLocaleTimeString()});
    if(page==='log') renderLog();
    return d;
  } catch(e){
    setStatus('err','Network error','');
    const d={success:false,error:e.message};
    log.unshift({action:body.action||'?',data:d,time:new Date().toLocaleTimeString()});
    return d;
  } finally {
    hideLoader();
  }
}
function setStatus(s,t,q){
  document.getElementById('dot').className='dot '+s;
  document.getElementById('stx').textContent=t;
  document.getElementById('ssql').textContent=q.length>100?q.slice(0,100)+'...':q;
}
async function loadTables(){
  splashStep();
  const res = await api({action:'list_tables'});
  splashStep();
  tables = res.result?.[0]?.results||[];
  const el = document.getElementById('tl');
  if(!tables.length){
    el.innerHTML='<div style="padding:24px 12px;text-align:center;color:var(--mu2);font-size:12px">No tables.<br>Create one in Tables tab.</div>';
    return;
  }
  el.innerHTML = tables.map(t=>`
    <div class="ti ${activeTable===t.name?'on':''}" onclick="sel('${esc(t.name)}')">
      <span class="ti-dot"></span>
      <span class="ti-n" title="${esc(t.name)}">${esc(t.name)}</span>
      <span class="ti-c" id="cn-${esc(t.name)}">...</span>
    </div>`).join('');
  tables.forEach(t=>{
    fetch(PROXY,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'raw',sql:`SELECT COUNT(*) as c FROM \`${t.name.replace(/`/g,'``')}\``})})
      .then(r=>r.json()).then(d=>{
        const e=document.getElementById('cn-'+t.name);
        if(e) e.textContent=d.result?.[0]?.results?.[0]?.c??'?';
      }).catch(()=>{});
  });
  splashStep();
}
function sel(name){
  activeTable = name;
  document.querySelectorAll('.ti').forEach(e=>e.classList.toggle('on',e.querySelector('.ti-n').title===name));
  showPage('browser');
}
function showPage(p){
  page = p;
  document.querySelectorAll('.nb').forEach((b,i)=>b.classList.toggle('on',['browser','tables','crud','raw','log','studio'][i]===p));
  ({browser:renderBrowser,tables:renderTables,crud:renderCRUD,raw:renderRaw,log:renderLog,studio:renderStudio}[p]||renderBrowser)();
}
function setContent(tabs,html){
  document.getElementById('tbar').innerHTML=tabs;
  document.getElementById('panels').innerHTML=html;
}
async function renderBrowser(){
  if(!activeTable){
    setContent('',`<div class="pn on"><div class="es"><div class="bi"><svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" opacity=".4"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/></svg></div><p>Select a table from the sidebar<br><span style="font-size:11px;color:var(--mu2)">or create one in the Tables tab</span></p></div></div>`);
    return;
  }
  setContent(
    `<button class="tab on" onclick="bData()">Data</button>
     <button class="tab" onclick="bSchema()">Schema</button>
     <button class="tab" onclick="bIndex()">Indexes</button>`,
    `<div class="pn on" id="bp"><div style="padding:48px;text-align:center;color:var(--mu)">Loading...</div></div>`
  );
  await bData();
}
async function bData(){
  actTab(0);
  const w=document.getElementById('bw')?.value||'';
  const l=document.getElementById('bl')?.value||100;
  const o=document.getElementById('bo')?.value||'';
  const res = await api({action:'select',table:activeTable,where:w,limit:parseInt(l),order:o});
  const rows = res.result?.[0]?.results||[];
  const meta = res.result?.[0]?.meta||{};
  document.getElementById('bp').innerHTML=`<div style="padding:18px">
    <div class="fr" style="margin-bottom:14px">
      <div class="f" style="flex:2"><label>WHERE</label><input type="text" id="bw" value="${esc(w)}" placeholder="id > 5 AND name LIKE '%a%'"></div>
      <div class="f" style="flex:1"><label>ORDER BY</label><input type="text" id="bo" value="${esc(o)}" placeholder="id DESC"></div>
      <div class="f" style="width:90px"><label>Limit</label><input type="number" id="bl" value="${l}"></div>
      <div style="padding-top:18px"><button class="btn btn-info" onclick="bData()">
        <svg width="11" height="11" viewBox="0 0 12 12" fill="currentColor"><polygon points="2,1 11,6 2,11"/></svg>
        Run
      </button></div>
    </div>
    <div style="margin-bottom:10px;font-size:12px;color:var(--mu)"><b style="color:var(--gr)">${rows.length}</b> row(s)${meta.duration?' &middot; <span style="color:var(--acc2)">'+meta.duration.toFixed(1)+'ms</span>':''}</div>
    ${rows.length ? buildTable(rows) : '<div style="color:var(--mu);padding:24px;text-align:center;border:1px dashed var(--b1);border-radius:var(--r)">No rows found</div>'}
  </div>`;
}
async function bSchema(){
  actTab(1);
  const res = await api({action:'table_info',table:activeTable});
  const cols = res.info?.result?.[0]?.results||[];
  const cnt = res.count?.result?.[0]?.results?.[0]?.cnt??'?';
  const TYPES = ['TEXT','INTEGER','REAL','BLOB','NUMERIC'];
  document.getElementById('bp').innerHTML=`<div style="padding:18px">
    <div class="card card-glow">
      <div class="ct">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><rect x="1" y="1" width="14" height="14" rx="2"/><path d="M1 6h14M1 10h14M6 1v14" stroke="var(--bg)" stroke-width=".5" fill="none"/></svg>
        ${esc(activeTable)}
      </div>
      <div class="kv"><span class="k">Rows</span><span class="v">${cnt}</span><span class="k">Columns</span><span class="v">${cols.length}</span></div>
    </div>
    <div class="stl">Columns</div>
    <div class="tw"><table>
      <thead><tr><th>#</th><th>Name</th><th>Type</th><th>Not Null</th><th>Default</th><th>PK</th></tr></thead>
      <tbody>${cols.map(c=>`<tr>
        <td>${c.cid}</td><td><b>${esc(c.name)}</b></td>
        <td><span class="sb2">${esc(c.type||'TEXT')}</span></td>
        <td>${c.notnull?`<svg width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M2 6.5l3 3 6-6" stroke="var(--gr)" stroke-width="1.8" stroke-linecap="round"/></svg>`:''}</td>
        <td class="${c.dflt_value==null?'nv':''}">${c.dflt_value!=null?esc(c.dflt_value):'NULL'}</td>
        <td>${c.pk?'<span class="sb2 pk-b">PK</span>':''}</td>
      </tr>`).join('')}</tbody>
    </table></div>
    <div class="div"></div>
    <div class="stl">Add Column</div>
    <div class="fg">
      <div class="f" style="flex:2"><label>Name</label><input id="acn" type="text" placeholder="new_column"></div>
      <div class="f" style="flex:1"><label>Type</label>${mkSel('act', TYPES, 'TEXT')}</div>
      <div class="f" style="flex:1"><label>Default</label><input id="acd" type="text" placeholder="optional"></div>
      <div style="padding-top:18px"><button class="btn btn-ok" onclick="doAddCol()">+ Add</button></div>
    </div>
  </div>`;
}
async function bIndex(){
  actTab(2);
  const res = await api({action:'table_info',table:activeTable});
  const idxs = res.indexes?.result?.[0]?.results||[];
  document.getElementById('bp').innerHTML=`<div style="padding:18px">
    <div class="stl">Indexes (${idxs.length})</div>
    ${idxs.length?`<div class="tw"><table><thead><tr><th>Name</th><th>Unique</th><th>Origin</th><th></th></tr></thead><tbody>
      ${idxs.map(i=>`<tr><td><b>${esc(i.name)}</b></td>
        <td>${i.unique?`<svg width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M2 6.5l3 3 6-6" stroke="var(--gr)" stroke-width="1.8" stroke-linecap="round"/></svg>`:''}</td>
        <td>${esc(i.origin)}</td>
        <td><button class="btn btn-d btn-sm" onclick="doDropIdx('${esc(i.name)}')">Drop</button></td></tr>`).join('')}
    </tbody></table></div>`:'<div style="color:var(--mu);margin-bottom:12px;padding:18px;border:1px dashed var(--b1);border-radius:var(--r);text-align:center">No indexes on this table</div>'}
    <div class="div"></div>
    <div class="stl">Create Index</div>
    <div class="fg">
      <div class="f" style="flex:2"><label>Index Name (optional)</label><input id="cin" type="text" placeholder="auto-generated"></div>
      <div class="f" style="flex:2"><label>Columns (comma-separated)</label><input id="cic" type="text" placeholder="col1, col2"></div>
      <div style="padding-top:18px;display:flex;gap:8px;align-items:center">
        ${mkCx('ciu','Unique')}
        <button class="btn btn-ok" onclick="doCreateIdx()">+ Create</button>
      </div>
    </div>
  </div>`;
}
function actTab(i){ document.querySelectorAll('.tab').forEach((t,j)=>t.classList.toggle('on',i===j)); }
async function doAddCol(){
  const name=document.getElementById('acn').value.trim();
  if(!name){await _modal.alert('Column name is required.',{title:'Missing Field',type:'warn'});return;}
  const res=await api({action:'add_column',table:activeTable,column:{name,type:document.getElementById('act').value,default:document.getElementById('acd').value.trim()}});
  if(res.success) bSchema();
  else await _modal.alert('<strong>Error:</strong> '+(res.errors?.[0]?.message||res.error||'Unknown error'),{title:'Operation Failed',type:'danger'});
}
async function doCreateIdx(){
  const cols=document.getElementById('cic').value.split(',').map(s=>s.trim()).filter(Boolean);
  if(!cols.length){await _modal.alert('Enter at least one column name.',{title:'Missing Field',type:'warn'});return;}
  const uniCb = document.querySelector('#cic')?.closest('.fg')?.querySelector('input[name=ciu]');
  const res=await api({action:'create_index',table:activeTable,columns:cols,name:document.getElementById('cin').value.trim(),unique:uniCb?.checked||false});
  if(res.success) bIndex();
  else await _modal.alert('<strong>Error:</strong> '+(res.errors?.[0]?.message||res.error||'Unknown error'),{title:'Operation Failed',type:'danger'});
}
async function doDropIdx(name){
  const ok=await _modal.confirm(`Drop index <strong>${esc(name)}</strong>?`,{title:'Drop Index',type:'danger',ok:'Drop Index'});
  if(!ok) return;
  const res=await api({action:'drop_index',name});
  if(res.success) bIndex();
  else await _modal.alert('<strong>Error:</strong> '+(res.errors?.[0]?.message||res.error),{title:'Failed',type:'danger'});
}
function renderTables(){
  const TYPES = ['TEXT','INTEGER','REAL','BLOB','NUMERIC'];
  const tableOpts = [['','-- select table --'], ...tables.map(t=>[t.name, t.name])].map(([v,l])=>({value:v,label:l}));
  setContent('',`<div class="pn on" style="padding:18px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div class="card card-glow">
        <div class="ct">
          <svg width="13" height="13" viewBox="0 0 13 13" fill="currentColor"><path d="M6.5 1v11M1 6.5h11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          Create Table
        </div>
        <div class="f" style="margin-bottom:11px"><label>Table Name</label><input id="ctn" type="text" placeholder="my_table"></div>
        <div style="display:grid;grid-template-columns:2fr 1fr .5fr .5fr .5fr 1fr 22px;gap:4px;font-size:10px;color:var(--mu2);font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:0 9px;margin-bottom:4px">
          <span>Name</span><span>Type</span><span>PK</span><span>AI</span><span>NN</span><span>Default</span><span></span>
        </div>
        <div class="cb" id="colb"></div>
        <div style="margin-top:10px;display:flex;gap:8px">
          <button class="btn btn-g btn-sm" onclick="addCR()">+ Column</button>
          <button class="btn btn-p" onclick="doCreate()">Create Table</button>
        </div>
      </div>
      <div class="card card-glow">
        <div class="ct">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M2 4h12v1H2zm0 4h12v1H2zm0 4h8v1H2z"/><path d="M12 10l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
          Table Actions
        </div>
        <div class="f" style="margin-bottom:11px">
          <label>Table</label>
          ${mkSel('tat', tableOpts, activeTable||'', 'onchange="activeTable=this.value"')}
        </div>
        <div class="div"></div>
        <div class="fg" style="margin-bottom:11px">
          <div class="f" style="flex:1"><label>Rename To</label><input id="tann" type="text" placeholder="new_name"></div>
          <div style="padding-top:18px"><button class="btn btn-warn" onclick="doRename()">Rename</button></div>
        </div>
        <div class="div"></div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-warn" onclick="doTrunc()">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M3 3h10v2H3zm1 3h8l-1 8H5L4 6z"/></svg>
            Truncate
          </button>
          <button class="btn btn-d" onclick="doDrop()">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>
            Drop Table
          </button>
        </div>
      </div>
    </div>
    <div class="card" style="margin-top:0">
      <div class="ct">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><rect x="1" y="1" width="14" height="14" rx="2"/><path d="M1 5h14M1 9h14M1 13h14M5 1v14M9 1v14" stroke="var(--bg)" stroke-width=".5" fill="none"/></svg>
        All Tables (${tables.length})
      </div>
      ${tables.length?`<div class="tw"><table>
        <thead><tr><th>Name</th><th>DDL Preview</th><th>Actions</th></tr></thead>
        <tbody>${tables.map(t=>`<tr>
          <td><b>${esc(t.name)}</b></td>
          <td style="max-width:360px;font-size:11px;color:var(--mu)">${esc((t.sql||'').slice(0,120))}</td>
          <td style="white-space:nowrap">
            <button class="btn btn-info btn-sm" onclick="sel('${esc(t.name)}')">Browse</button>
            <button class="btn btn-d btn-sm" onclick="cfDrop('${esc(t.name)}')">Drop</button>
          </td>
        </tr>`).join('')}</tbody>
      </table></div>`:'<div style="color:var(--mu)">No tables yet. Create one above.</div>'}
    </div>
  </div>`);
  addCR('id','INTEGER',true,true,true,'');
  addCR('created_at','TEXT',false,false,false,'');
}
const CSEL_ARROW = `<svg class="csel-arrow" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6l4 4 4-4"/></svg>`;
function addCR(name='',type='TEXT',pk=false,ai=false,nn=false,def=''){
  const types=['TEXT','INTEGER','REAL','BLOB','NUMERIC'];
  const d=document.createElement('div');d.className='cr';
  d.innerHTML=`
    <input type="text" placeholder="column_name" value="${esc(name)}" style="flex:2">
    <div class="csel-wrap" style="flex:1"><select>${types.map(t=>`<option ${t===type?'selected':''}>${t}</option>`).join('')}</select>${CSEL_ARROW}</div>
    ${mkCx('pk','PK',pk)}
    ${mkCx('ai','AI',ai)}
    ${mkCx('nn','NN',nn)}
    <input type="text" placeholder="default" value="${esc(def)}" style="flex:1">
    <button class="xb" onclick="this.closest('.cr').remove()"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M2 2l10 10M12 2L2 12"/></svg></button>`;
  document.getElementById('colb').appendChild(d);
}
async function doCreate(){
  const name=document.getElementById('ctn').value.trim();
  if(!name){await _modal.alert('Please enter a table name.',{title:'Missing Field',type:'warn'});return;}
  const rows=document.querySelectorAll('#colb .cr');
  if(!rows.length){await _modal.alert('Add at least one column.',{title:'No Columns',type:'warn'});return;}
  const columns=Array.from(rows).map(r=>{
    const ins=r.querySelectorAll('input[type=text]');
    const cbs=r.querySelectorAll('input[type=checkbox]');
    return{name:ins[0].value.trim(),type:r.querySelector('select').value,pk:cbs[0].checked,ai:cbs[1].checked,notnull:cbs[2].checked,default:ins[1].value.trim()};
  }).filter(c=>c.name);
  const res=await api({action:'create_table',table:name,columns});
  if(res.success){await loadTables();sel(name);}
  else await _modal.alert('<strong>Error:</strong> '+(res.errors?.[0]?.message||res.error),{title:'Create Failed',type:'danger'});
}
async function doRename(){
  const t=document.getElementById('tat').value,n=document.getElementById('tann').value.trim();
  if(!t||!n){await _modal.alert('Select a table and enter a new name.',{title:'Missing Fields',type:'warn'});return;}
  const ok=await _modal.confirm(`Rename <strong>${esc(t)}</strong> to <strong>${esc(n)}</strong>?`,{title:'Rename Table',type:'info',ok:'Rename'});
  if(!ok) return;
  const res=await api({action:'rename_table',table:t,new_name:n});
  if(res.success){if(activeTable===t)activeTable=n;await loadTables();renderTables();}
  else await _modal.alert('<strong>Error:</strong> '+(res.errors?.[0]?.message||res.error),{title:'Rename Failed',type:'danger'});
}
async function doTrunc(){
  const t=document.getElementById('tat').value;
  if(!t){await _modal.alert('Select a table first.',{title:'No Table',type:'warn'});return;}
  const ok=await _modal.confirm(`Truncate ALL rows from <strong>${esc(t)}</strong>? This cannot be undone.`,{title:'Truncate Table',type:'danger',ok:'Truncate All'});
  if(!ok) return;
  await api({action:'truncate_table',table:t});
  await loadTables();
}
async function doDrop(){
  const t=document.getElementById('tat').value;
  if(!t){await _modal.alert('Select a table first.',{title:'No Table',type:'warn'});return;}
  await cfDrop(t);
}
async function cfDrop(t){
  const ok=await _modal.confirm(`Permanently drop table <strong>${esc(t)}</strong>? All data will be lost forever.`,{title:'Drop Table',type:'danger',ok:'Drop Table'});
  if(!ok) return;
  const res=await api({action:'drop_table',table:t});
  if(res.success){if(activeTable===t)activeTable=null;await loadTables();renderTables();}
  else await _modal.alert('<strong>Error:</strong> '+(res.errors?.[0]?.message||res.error),{title:'Drop Failed',type:'danger'});
}
function renderCRUD(){
  setContent(
    `<button class="tab on" onclick="swCRUD('ins',this)">Insert</button>
     <button class="tab" onclick="swCRUD('upd',this)">Update</button>
     <button class="tab" onclick="swCRUD('del',this)">Delete</button>`,
    `<div class="pn on" id="cp"><div style="padding:18px">${cInsHTML()}</div></div>`
  );
  addKV();addKV();
}
function swCRUD(t,btn){
  document.querySelectorAll('.tab').forEach(b=>b.classList.remove('on'));
  btn.classList.add('on');
  const h={ins:cInsHTML,upd:cUpdHTML,del:cDelHTML};
  document.getElementById('cp').innerHTML=`<div style="padding:18px">${(h[t]||cInsHTML)()}</div>`;
  if(t!=='del'){addKV();addKV();}
}
function cInsHTML(){return`<div class="card card-glow"><div class="ct">
  <svg width="13" height="13" viewBox="0 0 13 13" fill="currentColor"><path d="M6.5 1v11M1 6.5h11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
  Insert Row</div>
  <div class="f" style="margin-bottom:11px"><label>Table</label><input id="it" type="text" value="${esc(activeTable||'')}" placeholder="table_name"></div>
  <div class="stl">Columns &amp; Values</div>
  <div id="kvb"></div>
  <div style="margin-top:8px;display:flex;gap:8px">
    <button class="btn btn-g btn-sm" onclick="addKV()">+ Row</button>
    <button class="btn btn-ok" onclick="doIns()">Insert Row</button>
  </div></div>`;}
function cUpdHTML(){return`<div class="card card-glow"><div class="ct">
  <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M11 2l3 3-7 7-4 1 1-4 7-7z"/></svg>
  Update Rows</div>
  <div class="fr" style="margin-bottom:11px">
    <div class="f"><label>Table</label><input id="ut" type="text" value="${esc(activeTable||'')}" placeholder="table_name"></div>
    <div class="f"><label>WHERE (required)</label><input id="uw" type="text" placeholder="id = 1"></div>
  </div>
  <div class="stl">SET</div>
  <div id="kvb"></div>
  <div style="margin-top:8px;display:flex;gap:8px">
    <button class="btn btn-g btn-sm" onclick="addKV()">+ Row</button>
    <button class="btn btn-warn" onclick="doUpd()">Update Rows</button>
  </div></div>`;}
function cDelHTML(){return`<div class="card card-glow"><div class="ct">
  <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>
  Delete Rows</div>
  <div class="fr">
    <div class="f"><label>Table</label><input id="dt" type="text" value="${esc(activeTable||'')}" placeholder="table_name"></div>
    <div class="f"><label>WHERE (required)</label><input id="dw" type="text" placeholder="id = 1"></div>
  </div>
  <div class="wb" style="margin-top:11px">
    <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M8 1L1 14h14L8 1zm0 9a1 1 0 110 2 1 1 0 010-2zm-1-5h2v4H7V5z"/></svg>
    WHERE clause is required. Omitting it will be blocked.
  </div>
  <div style="margin-top:11px"><button class="btn btn-d" onclick="doDel()">
    <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>
    Delete Rows
  </button></div></div>`;}
function addKV(k='',v=''){
  const b=document.getElementById('kvb');if(!b)return;
  const d=document.createElement('div');
  d.style.cssText='display:flex;gap:8px;margin-bottom:6px;align-items:center';
  d.innerHTML=`<input type="text" placeholder="column" value="${esc(k)}" style="flex:1;background:var(--s3);border:1px solid var(--b2);border-radius:4px;padding:6px 8px;color:var(--tx);font-family:var(--mono);font-size:12px">
    <input type="text" placeholder="value" value="${esc(v)}" style="flex:2;background:var(--s3);border:1px solid var(--b2);border-radius:4px;padding:6px 8px;color:var(--tx);font-family:var(--mono);font-size:12px">
    <button onclick="this.parentElement.remove()" style="background:none;border:none;color:var(--mu2);cursor:pointer;padding:0 4px;line-height:1;display:flex;align-items:center">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M2 2l10 10M12 2L2 12"/></svg>
    </button>`;
  b.appendChild(d);
}
function getKV(){
  const cols=[],vals=[];
  document.querySelectorAll('#kvb>div').forEach(r=>{
    const[k,v]=r.querySelectorAll('input');
    if(k.value.trim()){cols.push(k.value.trim());vals.push(v.value.trim());}
  });
  return{cols,vals};
}
async function doIns(){
  const t=document.getElementById('it')?.value.trim();
  if(!t){await _modal.alert('Table name is required.',{title:'Missing Field',type:'warn'});return;}
  const{cols,vals}=getKV();
  if(!cols.length){await _modal.alert('Add at least one column/value pair.',{title:'No Data',type:'warn'});return;}
  const res=await api({action:'insert',table:t,columns:cols,values:vals});
  if(!res.success) await _modal.alert('<strong>Error:</strong> '+(res.errors?.[0]?.message||res.error),{title:'Insert Failed',type:'danger'});
  else await _modal.alert('Row inserted successfully.',{title:'Success',type:'success',cancel:false});
}
async function doUpd(){
  const t=document.getElementById('ut')?.value.trim(),w=document.getElementById('uw')?.value.trim();
  if(!t||!w){await _modal.alert('Table name and WHERE clause are required.',{title:'Missing Fields',type:'warn'});return;}
  const{cols,vals}=getKV();
  if(!cols.length){await _modal.alert('Add at least one column/value pair.',{title:'No Data',type:'warn'});return;}
  const set={};cols.forEach((c,i)=>set[c]=vals[i]);
  const res=await api({action:'update',table:t,set,where:w});
  if(!res.success) await _modal.alert('<strong>Error:</strong> '+(res.errors?.[0]?.message||res.error),{title:'Update Failed',type:'danger'});
  else await _modal.alert('Rows updated successfully.',{title:'Success',type:'success',cancel:false});
}
async function doDel(){
  const t=document.getElementById('dt')?.value.trim(),w=document.getElementById('dw')?.value.trim();
  if(!t){await _modal.alert('Table name is required.',{title:'Missing Field',type:'warn'});return;}
  if(!w){await _modal.alert('WHERE clause is required.',{title:'WHERE Required',type:'danger'});return;}
  const ok=await _modal.confirm(`Delete from <strong>${esc(t)}</strong> WHERE <strong>${esc(w)}</strong>?`,{title:'Delete Rows',type:'danger',ok:'Delete'});
  if(!ok) return;
  const res=await api({action:'delete',table:t,where:w});
  if(!res.success) await _modal.alert('<strong>Error:</strong> '+(res.errors?.[0]?.message||res.error),{title:'Delete Failed',type:'danger'});
  else await _modal.alert('Rows deleted successfully.',{title:'Success',type:'success',cancel:false});
}
function renderRaw(){
  setContent('',`<div class="pn on" style="padding:18px">
    <div class="card card-glow">
      <div class="ct">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><polygon points="2,1 14,8 2,15"/></svg>
        Raw SQL
      </div>
      <div class="f" style="margin-bottom:11px">
        <label>SQL Query</label>
        <textarea id="rsql" class="code-area">SELECT * FROM sqlite_master WHERE type='table'</textarea>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="doRaw()">
          <svg width="11" height="11" viewBox="0 0 12 12" fill="currentColor"><polygon points="2,1 11,6 2,11"/></svg>
          Execute
        </button>
        <button class="btn btn-g" onclick="document.getElementById('rsql').value=''">Clear</button>
        <button class="btn btn-g btn-sm" onclick="snip('SELECT * FROM \`table_name\` LIMIT 100')">SELECT</button>
        <button class="btn btn-g btn-sm" onclick="snip('CREATE TABLE \`my_table\` (\`id\` INTEGER PRIMARY KEY AUTOINCREMENT, \`name\` TEXT NOT NULL, \`value\` TEXT, \`created_at\` TEXT)')">CREATE</button>
        <button class="btn btn-g btn-sm" onclick="snip('ALTER TABLE \`my_table\` ADD COLUMN \`new_col\` TEXT')">ALTER</button>
        <button class="btn btn-g btn-sm" onclick="snip('DROP TABLE IF EXISTS \`my_table\`')">DROP</button>
        <button class="btn btn-g btn-sm" onclick="snip('PRAGMA table_info(\`my_table\`)')">PRAGMA</button>
        <button class="btn btn-g btn-sm" onclick="snip(\"SELECT * FROM sqlite_master WHERE type='table'\")">List Tables</button>
      </div>
    </div>
    <div id="rr"></div>
  </div>`);
}
function snip(s){ document.getElementById('rsql').value=s; }
async function doRaw(){
  const sql=document.getElementById('rsql')?.value.trim();
  if(!sql) return;
  const res=await api({action:'raw',sql});
  const rows=res.result?.[0]?.results||[];
  const meta=res.result?.[0]?.meta||{};
  const el=document.getElementById('rr');if(!el) return;
  el.innerHTML=`<div class="card">
    <div style="margin-bottom:10px;font-size:12px;color:var(--mu)">
      ${res.success?`<svg width="12" height="12" viewBox="0 0 13 13" fill="none" style="vertical-align:-2px;margin-right:3px"><path d="M2 6.5l3 3 6-6" stroke="var(--gr)" stroke-width="1.8" stroke-linecap="round"/></svg><b style="color:var(--gr)">OK</b>`:`<b style="color:var(--rd)">Error</b>`}
      &middot; ${rows.length} row(s)
      ${meta.rows_written>0?' &middot; <b style="color:var(--gr)">'+meta.rows_written+' written</b>':''}
      ${meta.duration?' &middot; <span style="color:var(--acc2)">'+meta.duration.toFixed(1)+'ms</span>':''}
    </div>
    ${!res.success?`<div class="wb">${esc(res.errors?.[0]?.message||res.error||'Unknown error')}</div>`:''}
    ${rows.length?buildTable(rows):''}
    <div class="ls" style="margin-top:10px">${escH(res.sql||sql)}</div>
  </div>`;
}
function renderLog(){
  const TM={
    create_table:['t-ddl','DDL'],drop_table:['t-ddl','DDL'],rename_table:['t-ddl','DDL'],
    truncate_table:['t-del','TRUNC'],add_column:['t-ddl','ALTER'],
    create_index:['t-ddl','IDX'],drop_index:['t-ddl','IDX'],
    insert:['t-dml','INS'],update:['t-dml','UPD'],delete:['t-del','DEL'],
    select:['t-sel','SEL'],list_tables:['t-sel','SEL'],table_info:['t-sel','INFO'],raw:['t-raw','SQL']
  };
  setContent('',`<div class="pn on" style="padding:18px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <span style="color:var(--mu);font-size:12px;font-weight:600">${log.length} queries in session</span>
      <button class="btn btn-g btn-sm" onclick="log=[];renderLog()">Clear Log</button>
    </div>
    ${!log.length?`<div class="es"><div class="bi"><svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" opacity=".4"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 8h6M9 12h4"/></svg></div><p>No queries yet</p></div>`:
    `<div class="lw">${log.map((e,i)=>{
      const[tc,tt]=TM[e.action]||['t-raw','SQL'];
      const ok=e.data.success;
      const rows=e.data.result?.[0]?.results||[];
      const meta=e.data.result?.[0]?.meta||{};
      return`<div class="le">
        <div class="lh" onclick="tgl(${i})">
          <span class="lt ${tc}">${tt}</span>
          <span class="ld">${esc(e.action.replace(/_/g,' '))}</span>
          <span class="${ok?'lok':'ler'}">${ok?`<svg width="11" height="11" viewBox="0 0 13 13" fill="none" style="vertical-align:-1px;margin-right:2px"><path d="M2 6.5l3 3 6-6" stroke="var(--gr)" stroke-width="1.8" stroke-linecap="round"/></svg>OK`:`<svg width="11" height="11" viewBox="0 0 14 14" fill="none" stroke="var(--rd)" stroke-width="1.8" stroke-linecap="round" style="vertical-align:-1px;margin-right:2px"><path d="M2 2l10 10M12 2L2 12"/></svg>Err`}</span>
          <span style="font-size:10px;color:var(--mu2)">${rows.length?rows.length+'r':''} ${meta.rows_written>0?meta.rows_written+'w':''}</span>
          <span style="font-size:10px;color:var(--mu2)">${e.time}</span>
        </div>
        <div class="lb" id="lb-${i}">
          ${e.data.sql?`<div class="ls">${escH(e.data.sql)}</div>`:''}
          ${!ok?`<div class="wb">${esc(e.data.errors?.[0]?.message||e.data.error||'error')}</div>`:''}
          ${rows.length?buildTable(rows):''}
          <details><summary style="font-size:11px;color:var(--mu);cursor:pointer;margin-top:4px">Raw JSON</summary>
            <div class="lj">${escH(JSON.stringify(e.data,null,2))}</div>
          </details>
        </div>
      </div>`;}).join('')}</div>`}
  </div>`);
}
function tgl(i){ document.getElementById('lb-'+i)?.classList.toggle('on'); }
const STUDIO_LS_CSS = 'd1c_custom_css';
const STUDIO_LS_JS = 'd1c_custom_js';
const CSS_PRESETS = {
  'Default Dark': '',
  'Neon Purple': `:root{--acc:#bf5fff;--acc2:#d88aff;--acc-dim:#bf5fff1a;--acc3:#ebbfff;--bg:#0a0612;--s1:#100a1e;--s3:#1a1030;--b1:#2a1a45;--b2:#3a2060;}`,
  'Ocean Blue': `:root{--acc:#0ea5e9;--acc2:#38bdf8;--acc-dim:#0ea5e91a;--acc3:#7dd3fc;--bg:#030d14;--s1:#061523;--s3:#0a2035;--b1:#0f3050;--b2:#1a4070;}`,
  'Emerald': `:root{--acc:#10b981;--acc2:#34d399;--acc-dim:#10b9811a;--acc3:#6ee7b7;--bg:#030f0a;--s1:#051a10;--s3:#082a18;--b1:#0f3d20;--b2:#155028;}`,
  'Rose Gold': `:root{--acc:#fb7185;--acc2:#fda4af;--acc-dim:#fb71851a;--acc3:#fecdd3;--bg:#0f0709;--s1:#1c0d10;--s3:#2a1218;--b1:#401a20;--b2:#552030;}`,
  'Amber': `:root{--acc:#f59e0b;--acc2:#fbbf24;--acc-dim:#f59e0b1a;--acc3:#fde68a;--bg:#0d0900;--s1:#1a1200;--s3:#281c00;--b1:#3d2c00;--b2:#523a00;}`,
};
const JS_PRESETS = {
  'None': '',
  'Clock in statusbar': `setInterval(()=>{const el=document.getElementById('ssql');if(el&&!el.dataset.apiMsg) el.textContent=new Date().toLocaleTimeString();},1000);`,
  'Table row count badge': `document.querySelector('.sb-head').insertAdjacentHTML('beforeend','<span style="font-size:9px;background:var(--acc-dim);color:var(--acc2);padding:1px 6px;border-radius:10px;font-family:var(--mono)">custom</span>');`,
};
let studioTab = 'css';
function renderStudio(){
  setContent('',`<div class="pn on" style="padding:16px;height:100%">
    <div class="studio-wrap">
      <div class="studio-editor">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <div class="studio-tabs">
            <button class="studio-tab on" id="stab-css" onclick="switchStudioTab('css')">CSS</button>
            <button class="studio-tab" id="stab-js" onclick="switchStudioTab('js')">JavaScript</button>
          </div>
          <div style="display:flex;gap:6px">
            <button class="btn btn-ok btn-sm" onclick="applyStudio()">
              <svg width="11" height="11" viewBox="0 0 12 12" fill="currentColor"><polygon points="2,1 11,6 2,11"/></svg>
              Apply
            </button>
            <button class="btn btn-warn btn-sm" onclick="resetStudio()">Reset</button>
            <button class="btn btn-g btn-sm" onclick="exportStudio()">Export</button>
          </div>
        </div>
        <div id="studio-css-panel">
          <div class="preset-grid" id="css-presets"></div>
          <div class="f">
            <label>Custom CSS — injected into &lt;style id="custom-style"&gt;</label>
            <textarea class="code-area" id="css-editor" style="min-height:340px;background:var(--bg)" placeholder="/* Your custom CSS here */\n:root { --acc: #ff6b6b; }"></textarea>
          </div>
        </div>
        <div id="studio-js-panel" style="display:none">
          <div class="preset-grid" id="js-presets"></div>
          <div class="f">
            <label>Custom JavaScript — runs after page load</label>
            <textarea class="code-area" id="js-editor" style="min-height:340px;background:var(--bg)" placeholder="// Your custom JS here\n// You have access to all D1C functions"></textarea>
          </div>
          <div class="wb" style="margin-top:8px;font-size:11px">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M8 1L1 14h14L8 1zm0 9a1 1 0 110 2 1 1 0 010-2zm-1-5h2v4H7V5z"/></svg>
            Custom JS executes in the page scope. Be careful with what you run.
          </div>
        </div>
      </div>
      <div class="studio-preview">
        <div class="studio-preview-bar">
          <span>Live Preview — CSS Variables</span>
          <span style="font-family:var(--mono);font-size:10px;color:var(--acc2)" id="studio-save-indicator">Saved to localStorage</span>
        </div>
        <div class="studio-preview-frame">
          <div style="margin-bottom:14px">
            <div class="stl">Accent Color Palette</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
              <div style="width:36px;height:36px;border-radius:8px;background:var(--acc);border:1px solid var(--b2)"></div>
              <div style="width:36px;height:36px;border-radius:8px;background:var(--acc2);border:1px solid var(--b2)"></div>
              <div style="width:36px;height:36px;border-radius:8px;background:var(--acc3);border:1px solid var(--b2)"></div>
              <div style="width:36px;height:36px;border-radius:8px;background:var(--acc-dim);border:1px solid var(--b2)"></div>
            </div>
            <div class="stl">Backgrounds</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
              <div style="width:36px;height:36px;border-radius:8px;background:var(--bg);border:1px solid var(--b2)"></div>
              <div style="width:36px;height:36px;border-radius:8px;background:var(--s1);border:1px solid var(--b2)"></div>
              <div style="width:36px;height:36px;border-radius:8px;background:var(--s3);border:1px solid var(--b2)"></div>
            </div>
            <div class="stl">Borders</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
              <div style="width:36px;height:36px;border-radius:8px;background:var(--b1);border:1px solid var(--b2)"></div>
              <div style="width:36px;height:36px;border-radius:8px;background:var(--b2);border:1px solid var(--b2)"></div>
            </div>
          </div>
          <div class="div"></div>
          <div style="margin-bottom:12px">
            <div class="stl">Component Preview</div>
            <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px">
              <button class="btn btn-p btn-sm">Primary</button>
              <button class="btn btn-ok btn-sm">Success</button>
              <button class="btn btn-info btn-sm">Info</button>
              <button class="btn btn-warn btn-sm">Warn</button>
              <button class="btn btn-d btn-sm">Danger</button>
              <button class="btn btn-pu btn-sm">Purple</button>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
              ${mkCx('prev1','Checked option',true)}
              ${mkCx('prev2','Unchecked option',false)}
            </div>
            <div style="max-width:180px;margin-bottom:12px">
              ${mkSel('prev-sel',['TEXT','INTEGER','REAL','BLOB'],'TEXT')}
            </div>
            <div class="card" style="padding:12px;margin-bottom:8px">
              <div class="ct" style="margin-bottom:6px">Sample Card</div>
              <div class="kv"><span class="k">Status</span><span class="v" style="color:var(--gr)">Connected</span><span class="k">Accent</span><span class="v" style="color:var(--acc2)">var(--acc2)</span></div>
            </div>
            <div class="dot ok" style="display:inline-block;margin-right:6px"></div>
            <span style="font-size:11px;color:var(--mu)">Status dot preview</span>
          </div>
          <div class="div"></div>
          <div>
            <div class="stl">Variable Reference</div>
            <div style="font-family:var(--mono);font-size:10px;color:var(--mu);line-height:2">
              --acc &middot; --acc2 &middot; --acc3 &middot; --acc-dim<br>
              --bg &middot; --s1 &middot; --s2 &middot; --s3<br>
              --b1 &middot; --b2<br>
              --tx &middot; --mu &middot; --mu2<br>
              --gr &middot; --gr-bg &middot; --rd &middot; --rd-bg<br>
              --bl &middot; --bl-bg &middot; --am &middot; --am-bg<br>
              --pu &middot; --pu-bg &middot; --te &middot; --te-bg
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>`);
  const savedCSS = localStorage.getItem(STUDIO_LS_CSS)||'';
  const savedJS = localStorage.getItem(STUDIO_LS_JS)||'';
  document.getElementById('css-editor').value = savedCSS;
  document.getElementById('js-editor').value = savedJS;
  const cpg = document.getElementById('css-presets');
  Object.keys(CSS_PRESETS).forEach(name=>{
    const b = document.createElement('button');
    b.className = 'preset-btn';
    b.textContent = name;
    b.onclick = ()=>{ document.getElementById('css-editor').value = CSS_PRESETS[name]; applyStudio(); };
    cpg.appendChild(b);
  });
  const jpg = document.getElementById('js-presets');
  Object.keys(JS_PRESETS).forEach(name=>{
    const b = document.createElement('button');
    b.className = 'preset-btn';
    b.textContent = name;
    b.onclick = ()=>{ document.getElementById('js-editor').value = JS_PRESETS[name]; };
    jpg.appendChild(b);
  });
  let cssDebounce;
  document.getElementById('css-editor').addEventListener('input',()=>{
    clearTimeout(cssDebounce);
    cssDebounce = setTimeout(applyStudio, 600);
  });
}
function switchStudioTab(tab){
  studioTab = tab;
  document.getElementById('studio-css-panel').style.display = tab==='css'?'':'none';
  document.getElementById('studio-js-panel').style.display = tab==='js'?'':'none';
  document.getElementById('stab-css').classList.toggle('on',tab==='css');
  document.getElementById('stab-js').classList.toggle('on',tab==='js');
}
function applyStudio(){
  const css = document.getElementById('css-editor')?.value||'';
  document.getElementById('custom-style').textContent = css;
  localStorage.setItem(STUDIO_LS_CSS, css);
  const ind = document.getElementById('studio-save-indicator');
  if(ind){ ind.textContent = 'Saved '+new Date().toLocaleTimeString(); ind.style.color='var(--gr)'; setTimeout(()=>{if(ind)ind.style.color='';},2000); }
}
function resetStudio(){
  document.getElementById('css-editor').value = '';
  document.getElementById('js-editor').value = '';
  document.getElementById('custom-style').textContent = '';
  localStorage.removeItem(STUDIO_LS_CSS);
  localStorage.removeItem(STUDIO_LS_JS);
  const ind = document.getElementById('studio-save-indicator');
  if(ind) ind.textContent = 'Reset to defaults';
}
function exportStudio(){
  const css = document.getElementById('css-editor')?.value||'';
  const js = document.getElementById('js-editor')?.value||'';
  const out = `/* D1C Artoriasphere Custom Theme */\n${css}\n\n/* Custom JavaScript */\n/*\n${js}\n*/`;
  const b = new Blob([out],{type:'text/css'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(b);
  a.download = 'd1c-theme.css';
  a.click();
}
function buildTable(rows){
  if(!rows.length) return '';
  const keys=Object.keys(rows[0]);
  return`<div class="tw"><table>
    <thead><tr>${keys.map(k=>`<th>${escH(k)}</th>`).join('')}</tr></thead>
    <tbody>${rows.map(r=>`<tr>${keys.map(k=>`<td class="${r[k]==null?'nv':''}" title="${esc(String(r[k]??''))}">${r[k]==null?'NULL':escH(String(r[k]))}</td>`).join('')}</tr>`).join('')}</tbody>
  </table></div>`;
}
function escH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function esc(s){ return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
document.addEventListener('DOMContentLoaded',()=>{
  _modal.init();
  document.getElementById('modal-confirm').onclick=()=>{
    const inp=document.getElementById('_modal-inp');
    _modal._close(inp&&inp.style.display!=='none'?inp.value:true);
  };
  document.getElementById('modal-cancel').onclick=()=>_modal._close(false);
  document.getElementById('modal-overlay').addEventListener('click',e=>{
    if(e.target===document.getElementById('modal-overlay')) _modal._close(false);
  });
  document.addEventListener('keydown',e=>{
    if(!document.getElementById('modal-overlay').classList.contains('show')) return;
    if(e.key==='Escape') _modal._close(false);
    if(e.key==='Enter') document.getElementById('modal-confirm').click();
  });
  const savedCSS = localStorage.getItem(STUDIO_LS_CSS)||'';
  if(savedCSS) document.getElementById('custom-style').textContent = savedCSS;
  const savedJS = localStorage.getItem(STUDIO_LS_JS)||'';
  if(savedJS){
    try{ const fn=new Function(savedJS); fn(); }catch(e){ console.warn('Custom JS error:',e); }
  }
  loadTables().then(()=>{
    splashStep();
    setTimeout(()=>{
      document.getElementById('splash').classList.add('hidden');
      cancelAnimationFrame(splashRAF);
      renderBrowser();
    }, 700);
  });
});
</script>
</body>
</html>
