<?php
/**
 * generate_dynamic.php
 * Genera el CSV dinámico: columna HOST + una columna por cada item seleccionado.
 * Si un host no tiene el item → N/A.
 * Flujo: recibe hostids + itemids[] + item_names[] → consulta lastvalue → CSV.
 */
declare(strict_types=1);

set_time_limit(300);
ini_set('memory_limit', '512M');

session_start();

// CSRF
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403); die('Error: Invalid CSRF token.');
}
unset($_SESSION['csrf_token']);

if (empty($_SESSION['zbx_auth_ok'])) {
    http_response_code(403); die('Invalid session');
}

require_once __DIR__ . '/../../lib/i18n.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/ZabbixApiFactory.php';

// ── Helpers ───────────────────────────────────────────────────
function resolveHostIds(object $api): array {
    $hostIdsRaw  = array_filter(explode(',', $_POST['hostids']   ?? ''));
    $groupIdsRaw = array_filter(explode(',', $_POST['groupids']  ?? ''));
    $hostNames   = array_filter(array_map('trim', preg_split('/[,\r\n]+/', $_POST['hostnames']  ?? '')));
    $groupNames  = array_filter(array_map('trim', preg_split('/[,\r\n]+/', $_POST['hostgroups'] ?? '')));

    $fromNames = [];
    if (!empty($hostNames)) {
        $map = $api->getHostsByNames($hostNames);
        $fromNames = array_values($map);
    }

    $groupIdsFromNames = [];
    if (!empty($groupNames)) {
        $grps = $api->call('hostgroup.get', ['output'=>['groupid'],'filter'=>['name'=>$groupNames]]);
        if (is_array($grps)) $groupIdsFromNames = array_column($grps,'groupid');
    }

    $allGroupIds = array_unique(array_merge($groupIdsRaw, $groupIdsFromNames));
    $fromGroups  = [];
    if (!empty($allGroupIds)) {
        $fromGroups = $api->getHostIdsByGroupIds($allGroupIds);
    }

    return array_values(array_unique(array_merge($hostIdsRaw, $fromNames, $fromGroups)));
}

try {
    $api = ZabbixApiFactory::create(
        ZABBIX_API_URL,
        $_SESSION['zbx_user'],
        $_SESSION['zbx_pass'],
        ['timeout' => 280, 'verify_ssl' => defined('VERIFY_SSL') ? VERIFY_SSL : false]
    );

    // ── Resolve hosts ──────────────────────────────────────────
    $hostIds = resolveHostIds($api);
    if (empty($hostIds)) {
        die('<h3>Error: No se encontraron hosts.</h3><a href="index.php">Volver</a>');
    }

    // ── Items seleccionados — recibidos como "itemid|display_name" ──
    $itemData = array_filter(array_map('trim', (array)($_POST['item_data'] ?? [])));
    if (empty($itemData)) {
        die('<h3>Error: No se seleccionaron items.</h3><a href="index.php">Volver</a>');
    }

    // Parsear pares itemid|label
    $selectedItemids = [];
    $itemNames       = []; // idx => label
    foreach ($itemData as $pair) {
        $pos = strpos($pair, '|');
        if ($pos === false) continue;
        $iid   = trim(substr($pair, 0, $pos));
        $label = trim(substr($pair, $pos + 1));
        $selectedItemids[] = $iid;
        $itemNames[]       = $label !== '' ? $label : $iid;
    }
    if (empty($selectedItemids)) {
        die('<h3>Error: No se pudieron parsear los items.</h3><a href="index.php">Volver</a>');
    }

    // ── Obtener keys de referencia de los items seleccionados ──
    // (usamos el primer itemid como referencia de key_)
    $refItems = $api->call('item.get', [
        'output'  => ['itemid', 'key_', 'name'],
        'itemids' => $selectedItemids,
    ]);
    $refMap = []; // itemid => key_
    foreach ((array)$refItems as $item) {
        $refMap[$item['itemid']] = $item['key_'];
    }

    // Keys únicas a buscar
    $keysToFetch = array_unique(array_values($refMap));
    if (empty($keysToFetch)) {
        die('<h3>Error: No se pudieron resolver las keys de los items.</h3><a href="index.php">Volver</a>');
    }

    // ── Fetch de todos los items por key_ exacta en todos los hosts ──
    // Hacemos la consulta en lotes de 500 hosts para no saturar la API
    $allItemValues = []; // [hostid][key_] = lastvalue

    $batchSize = 500;
    $batches   = array_chunk($hostIds, $batchSize);

    foreach ($batches as $batch) {
        $batchItems = $api->call('item.get', [
            'output'  => ['itemid', 'key_', 'lastvalue', 'hostid'],
            'hostids' => $batch,
            'filter'  => ['key_' => $keysToFetch, 'status' => 0],
        ]);
        if (!is_array($batchItems)) continue;
        foreach ($batchItems as $item) {
            $allItemValues[$item['hostid']][$item['key_']] = $item['lastvalue'];
        }
    }

    // ── Obtener nombres de hosts (ordenados) ───────────────────
    $hostsInfo = $api->call('host.get', [
        'hostids'  => $hostIds,
        'output'   => ['hostid', 'name'],
        'sortfield'=> 'name',
        'sortorder'=> 'ASC',
    ]);
    $hostMap = [];
    foreach ((array)$hostsInfo as $h) $hostMap[$h['hostid']] = $h['name'];

    // ── Rango de fechas (para el preHeader) ────────────────────
    $zbx_tz   = defined('ZABBIX_TZ') ? ZABBIX_TZ : 'UTC';
    $clientTz = trim($_POST['client_tz'] ?? '');
    try { $tz = new DateTimeZone($clientTz ?: $zbx_tz); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
    $fromStr = $_POST['from_dt'] ?? '';
    $toStr   = $_POST['to_dt']   ?? '';
    $from_ts = ($fromStr && ($d=DateTime::createFromFormat('Y-m-d\TH:i',$fromStr,$tz))) ? $d->getTimestamp() : time()-86400;
    $to_ts   = ($toStr   && ($d=DateTime::createFromFormat('Y-m-d\TH:i',$toStr,  $tz))) ? $d->getTimestamp() : time();

    // ── Construir CSV ──────────────────────────────────────────
    $filename = 'zabbix_data_preview_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // BOM UTF-8 (para Excel)
    fwrite($output, "\xEF\xBB\xBF");

    // Pre-header con rango de fechas
    fputcsv($output, ['Reporte Data Preview — Zabbix Report'], ';');
    fputcsv($output, ['Generado:', date('Y-m-d H:i:s'), 'Rango:', date('Y-m-d H:i', $from_ts), '→', date('Y-m-d H:i', $to_ts)], ';');
    fputcsv($output, [], ';'); // fila vacía separadora

    // Agrupar items por display_name — varios itemids con el mismo nombre
    // visible se fusionan en UNA columna. Por host se toma el primer valor no-N/A.
    $colGroups = []; // [ display_name => [key1, key2, ...] ]
    foreach ($selectedItemids as $idx => $iid) {
        $colName = $itemNames[$idx];
        $key     = $refMap[$iid] ?? null;
        if ($key === null) continue;
        if (!isset($colGroups[$colName])) $colGroups[$colName] = [];
        if (!in_array($key, $colGroups[$colName], true)) $colGroups[$colName][] = $key;
    }

    // Headers: Host + un nombre por grupo (sin duplicados)
    $headers = ['Host'];
    foreach (array_keys($colGroups) as $colName) $headers[] = $colName;
    fputcsv($output, $headers, ';');

    // Filas: una por host
    foreach ($hostIds as $hid) {
        $row = [$hostMap[$hid] ?? $hid];
        foreach ($colGroups as $colName => $keys) {
            $val = 'N/A';
            foreach ($keys as $key) {
                $v = $allItemValues[$hid][$key] ?? null;
                if ($v !== null && $v !== '') { $val = $v; break; }
            }
            $row[] = $val;
        }
        fputcsv($output, $row, ';');
    }

    fclose($output);
    exit;

} catch (Throwable $e) {
    error_log('generate_dynamic.php error: ' . $e->getMessage());
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
    echo '<h3 style="color:#d9534f">❌ Error al generar el reporte</h3>';
    echo '<pre>'.htmlspecialchars($e->getMessage()).'</pre>';
    echo '<br><a href="index.php" style="display:inline-block;padding:9px 16px;background:#d9534f;color:#fff;text-decoration:none;border-radius:7px">← Volver</a>';
    echo '</body></html>';
}
