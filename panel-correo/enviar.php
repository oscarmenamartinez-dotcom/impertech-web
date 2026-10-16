<?php
/* =====================================================================
   Puente de correo del panel Impertech (https://panel-impertech.pages.dev)

   El panel corre en Cloudflare y desde ahí no puede conectarse al correo
   de Hostinger. Este archivo, alojado en impertech.cl, hace esa parte:
   recibe el mensaje ya armado por el panel, lo envía por SMTP desde el
   buzón y deja la copia en Enviados por IMAP.

   - Solo responde si llega la llave del panel (aquí se guarda solo su huella).
   - Solo se conecta a los servidores de correo de Hostinger.
   - No guarda nada: ni contraseñas, ni mensajes, ni registros.
   ===================================================================== */

const HUELLA = '4aa8113453ae98d82889582fe3ef07206d05b38183115690f8a1433d5fed5c4e';
const SERVIDORES = ['smtp.hostinger.com', 'imap.hostinger.com'];
const ESPERA = 20;          // segundos máximos esperando al servidor de correo
const MAXIMO = 9000000;     // bytes máximos de lo que manda el panel

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

class ErrorCorreo extends Exception {
  public $estado; public $codigo;
  function __construct($mensaje, $estado = 502, $codigo = 'correo') { parent::__construct($mensaje); $this->estado = $estado; $this->codigo = $codigo; }
}
function responder($datos, $estado = 200) { http_response_code($estado); echo json_encode($datos, JSON_UNESCAPED_UNICODE); exit; }
function falla($mensaje, $estado, $codigo) { responder(['ok' => false, 'error' => $mensaje, 'codigo' => $codigo], $estado); }

/* En las pruebas locales (php -S) se permite un servidor de correo en esta misma máquina. */
function pruebaLocal() { return PHP_SAPI === 'cli-server'; }
function servidorPermitido($h) { return in_array($h, SERVIDORES, true) || (pruebaLocal() && $h === '127.0.0.1'); }

/* ---------- conexión ---------- */
function abrir($host, $puerto, $ssl) {
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
  $dir = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . intval($puerto);
  $s = @stream_socket_client($dir, $errno, $errstr, ESPERA, STREAM_CLIENT_CONNECT, $ctx);
  if (!$s) throw new ErrorCorreo('No se pudo conectar con el servidor de correo ' . $host . '.', 502, 'conexion');
  stream_set_timeout($s, ESPERA);
  return $s;
}
function linea($s) {
  $l = fgets($s, 8192);
  if ($l === false) {
    $m = stream_get_meta_data($s);
    if (!empty($m['timed_out'])) throw new ErrorCorreo('El servidor de correo no respondió a tiempo.', 504, 'tiempo');
    throw new ErrorCorreo('El servidor de correo cortó la conexión.', 502, 'conexion');
  }
  return rtrim($l, "\r\n");
}
function escribir($s, $texto) {
  $total = strlen($texto); $hecho = 0;
  while ($hecho < $total) {
    $n = @fwrite($s, substr($texto, $hecho, 65536));
    if ($n === false || $n === 0) throw new ErrorCorreo('Se cortó la conexión con el servidor de correo.', 502, 'conexion');
    $hecho += $n;
  }
}

/* ---------- SMTP ---------- */
function respuestaSmtp($s) {
  $l = linea($s); $todo = [$l];
  while (preg_match('/^\d{3}-/', $l)) { $l = linea($s); $todo[] = $l; }
  return ['codigo' => intval(substr($l, 0, 3)), 'texto' => implode("\n", $todo)];
}
function pasoSmtp($s, $comando, $esperado, $problema = null) {
  if ($comando !== null) escribir($s, $comando . "\r\n");
  $r = respuestaSmtp($s);
  if (intdiv($r['codigo'], 100) !== intdiv($esperado, 100)) {
    throw $problema ? $problema($r) : new ErrorCorreo('El servidor de correo rechazó el envío (' . $r['codigo'] . ').', 502, 'rechazo');
  }
  return $r;
}
function entrarSmtp($d) {
  $ssl = $d['puerto'] === 465;
  $local = $d['host'] === '127.0.0.1';
  $s = abrir($d['host'], $d['puerto'], $ssl && !$local);
  try {
    pasoSmtp($s, null, 220);
    pasoSmtp($s, 'EHLO panel-impertech', 250);
    if (!$ssl && !$local) {
      pasoSmtp($s, 'STARTTLS', 220);
      if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new ErrorCorreo('No se pudo cifrar la conexión con el servidor de correo.', 502, 'conexion');
      pasoSmtp($s, 'EHLO panel-impertech', 250);
    }
    $u = $d['usuario'];
    pasoSmtp($s, 'AUTH PLAIN ' . base64_encode("\0" . $u . "\0" . $d['clave']), 235, function () use ($u) {
      return new ErrorCorreo('El buzón ' . $u . ' no aceptó la contraseña. Revísala en la configuración del correo.', 400, 'clave');
    });
    return $s;
  } catch (Exception $e) { @fclose($s); throw $e; }
}

/* ---------- IMAP ---------- */
function citaImap($v) { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"'; }
function pasoImap($s, $etiqueta, $comando) {
  escribir($s, $etiqueta . ' ' . $comando . "\r\n");
  $lineas = [];
  for (;;) {
    $l = linea($s);
    if (strpos($l, $etiqueta . ' ') === 0) {
      if (!preg_match('/^\S+ OK/i', $l)) throw new ErrorCorreo('IMAP: ' . substr($l, strlen($etiqueta) + 1, 120), 502, 'imap');
      return $lineas;
    }
    $lineas[] = $l;
  }
}
function carpetaEnviados($lineas) {
  $carpetas = [];
  foreach ($lineas as $l) {
    if (!preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (.+)$/i', $l, $m)) continue;
    $nombre = trim($m[2]);
    if ($nombre !== '' && $nombre[0] === '"') $nombre = preg_replace('/\\\\(.)/', '$1', substr($nombre, 1, -1));
    $carpetas[] = ['flags' => $m[1], 'nombre' => $nombre];
  }
  foreach ($carpetas as $c) if (preg_match('/\\\\Sent\b/i', $c['flags'])) return $c['nombre'];
  foreach ($carpetas as $c) if (preg_match('/(^|[.\/])(Sent|Enviados|Sent Items|Sent Messages|Elementos enviados)$/i', $c['nombre'])) return $c['nombre'];
  return 'INBOX.Sent';
}
function entrarImap($d) {
  $local = $d['imapHost'] === '127.0.0.1';
  $s = abrir($d['imapHost'], $d['imapPuerto'], !$local);
  try {
    $saludo = linea($s);
    if (!preg_match('/^\* OK/i', $saludo)) throw new ErrorCorreo('IMAP no saludó como se esperaba.', 502, 'imap');
    pasoImap($s, 'a1', 'LOGIN ' . citaImap($d['usuario']) . ' ' . citaImap($d['clave']));
    return $s;
  } catch (Exception $e) { @fclose($s); throw $e; }
}
function copiarEnEnviados($d, $mensaje) {
  $s = entrarImap($d);
  try {
    $carpeta = carpetaEnviados(pasoImap($s, 'a2', 'LIST "" "*"'));
    escribir($s, 'a3 APPEND ' . citaImap($carpeta) . ' (\\Seen) {' . strlen($mensaje) . "}\r\n");
    $listo = linea($s);
    if ($listo === '' || $listo[0] !== '+') throw new ErrorCorreo('IMAP no aceptó la copia.', 502, 'imap');
    escribir($s, $mensaje . "\r\n");
    for (;;) {
      $l = linea($s);
      if (strpos($l, 'a3 ') === 0) {
        if (!preg_match('/^a3 OK/i', $l)) throw new ErrorCorreo('IMAP no guardó la copia.', 502, 'imap');
        break;
      }
    }
    try { pasoImap($s, 'a4', 'LOGOUT'); } catch (Exception $e) { /* da igual al salir */ }
    return $carpeta;
  } finally { @fclose($s); }
}

/* ---------- entrada ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') falla('No disponible.', 405, 'metodo');
$llave = isset($_SERVER['HTTP_X_PUENTE']) ? $_SERVER['HTTP_X_PUENTE'] : '';
if (!is_string($llave) || $llave === '' || !hash_equals(HUELLA, hash('sha256', $llave))) falla('No autorizado.', 401, 'llave');

$cuerpo = file_get_contents('php://input', false, null, 0, MAXIMO + 1);
if ($cuerpo === false || strlen($cuerpo) > MAXIMO) falla('El mensaje es demasiado grande.', 413, 'datos');
$e = json_decode($cuerpo, true);
if (!is_array($e)) falla('Datos no válidos.', 400, 'datos');

$correo = '/^[^\s@<>",;:()]+@[^\s@<>",;:()]+\.[^\s@<>",;:()]{2,}$/';
$d = [
  'usuario' => isset($e['usuario']) ? strtolower(trim((string)$e['usuario'])) : '',
  'clave' => isset($e['clave']) ? (string)$e['clave'] : '',
  'host' => isset($e['host']) ? strtolower(trim((string)$e['host'])) : 'smtp.hostinger.com',
  'puerto' => isset($e['puerto']) ? intval($e['puerto']) : 465,
  'imapHost' => isset($e['imapHost']) ? strtolower(trim((string)$e['imapHost'])) : 'imap.hostinger.com',
  'imapPuerto' => isset($e['imapPuerto']) ? intval($e['imapPuerto']) : 993,
];
if (!preg_match($correo, $d['usuario']) || $d['clave'] === '' || preg_match('/[\r\n\0]/', $d['clave'])) falla('Faltan los datos del buzón.', 400, 'datos');
if (!servidorPermitido($d['host']) || !servidorPermitido($d['imapHost'])) falla('Ese servidor de correo no está permitido.', 400, 'datos');
if (!pruebaLocal() && (!in_array($d['puerto'], [465, 587], true) || $d['imapPuerto'] !== 993)) falla('Puerto de correo no permitido.', 400, 'datos');

$accion = isset($e['accion']) ? $e['accion'] : '';
try {
  if ($accion === 'probar') {
    $s = entrarSmtp($d);
    try { pasoSmtp($s, 'QUIT', 221); } catch (Exception $x) { /* da igual al salir */ }
    @fclose($s);
    $imap = '';
    try {
      $si = entrarImap($d);
      try { pasoImap($si, 'a2', 'LOGOUT'); } catch (Exception $x) { /* da igual */ }
      @fclose($si);
    } catch (Exception $x) {
      $imap = 'Se puede enviar, pero no se pudo entrar por IMAP para guardar la copia en Enviados.';
    }
    responder(['ok' => true, 'imap' => $imap]);
  }

  if ($accion === 'enviar') {
    $destinos = isset($e['destinos']) && is_array($e['destinos']) ? array_values($e['destinos']) : [];
    $mensaje = isset($e['mensaje']) ? (string)$e['mensaje'] : '';
    if (!$destinos || count($destinos) > 5) falla('Faltan los destinatarios.', 400, 'datos');
    foreach ($destinos as $p) if (!is_string($p) || !preg_match($correo, $p)) falla('Hay un destinatario no válido.', 400, 'datos');
    if ($mensaje === '' || preg_match('/(^|\r\n)\.\r\n/', $mensaje)) falla('El mensaje no es válido.', 400, 'datos');
    // Una línea que empieza con punto se duplica, como pide SMTP.
    $mensaje = preg_replace('/(^|\r\n)\./', '$1..', $mensaje);
    $s = entrarSmtp($d);
    try {
      pasoSmtp($s, 'MAIL FROM:<' . $d['usuario'] . '>', 250);
      foreach ($destinos as $p) {
        pasoSmtp($s, 'RCPT TO:<' . $p . '>', 250, function ($r) use ($p) {
          return new ErrorCorreo('El servidor no aceptó el destinatario ' . $p . ' (' . $r['codigo'] . ').', 400, 'destinatario');
        });
      }
      pasoSmtp($s, 'DATA', 354);
      escribir($s, rtrim($mensaje, "\r\n") . "\r\n.\r\n");
      pasoSmtp($s, null, 250);
      try { pasoSmtp($s, 'QUIT', 221); } catch (Exception $x) { /* ya se envió */ }
    } finally { @fclose($s); }
    $enviados = ''; $problema = '';
    try { $enviados = copiarEnEnviados($d, preg_replace('/(^|\r\n)\.\./', '$1.', $mensaje)); }
    catch (Exception $x) { $problema = 'El correo se envió, pero no se pudo guardar la copia en Enviados.'; }
    responder(['ok' => true, 'enviados' => $enviados, 'problema' => $problema]);
  }

  falla('Acción desconocida.', 400, 'datos');
} catch (ErrorCorreo $x) {
  falla($x->getMessage(), $x->estado, $x->codigo);
} catch (Throwable $x) {
  falla('Falló el envío del correo.', 502, 'correo');
}
