<?php
// Sistema de Citas Médicas — ASI Práctica 5, Equipo 7
// CRUD completo en un solo archivo: alta, consulta (con filtro), modificación y eliminación.
require __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function volver($msg, $tipo = 'ok') {
    header('Location: index.php?' . http_build_query(['msg' => $msg, 'tipo' => $tipo]));
    exit;
}

$ESTADOS = ['pendiente', 'confirmada', 'atendida', 'cancelada'];

// ---------- Conexión (con timeout para no colgarse si la VM de BD no responde) ----------
try {
    $conn = mysqli_init();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $conn->real_connect($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo '<h2>No se pudo conectar a la base de datos</h2>';
    echo '<p>Revisa la IP de srv-bd-dns en config.php, que MariaDB esté corriendo y que acepte conexiones remotas (bind-address = 0.0.0.0, puerto 3306).</p>';
    echo '<pre>' . h($e->getMessage()) . '</pre>';
    exit;
}

// ¿Ya hay otra cita activa con el mismo doctor, fecha y hora?
function hayEmpalme($conn, $doctor, $fecha, $hora, $excluirId = 0) {
    $st = $conn->prepare("SELECT COUNT(*) FROM citas
                          WHERE doctor = ? AND fecha = ? AND hora = ?
                            AND estado <> 'cancelada' AND id <> ?");
    $st->bind_param('sssi', $doctor, $fecha, $hora, $excluirId);
    $st->execute();
    $st->bind_result($n);
    $st->fetch();
    $st->close();
    return $n > 0;
}

// ---------- Acciones (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion   = $_POST['accion'] ?? '';
    $id       = (int)($_POST['id'] ?? 0);
    $paciente = trim($_POST['paciente'] ?? '');
    $doctor   = trim($_POST['doctor'] ?? '');
    $fecha    = $_POST['fecha'] ?? '';
    $hora     = $_POST['hora'] ?? '';
    $estado   = $_POST['estado'] ?? 'pendiente';

    try {
        if ($accion === 'crear' || $accion === 'actualizar') {
            if ($paciente === '' || $doctor === '' || $fecha === '' || $hora === '') {
                volver('Todos los campos son obligatorios.', 'error');
            }
            if (!in_array($estado, $ESTADOS, true)) $estado = 'pendiente';
            if ($estado !== 'cancelada' && hayEmpalme($conn, $doctor, $fecha, $hora, $id)) {
                volver("El doctor $doctor ya tiene una cita el $fecha a las $hora.", 'error');
            }
        }

        if ($accion === 'crear') {
            $st = $conn->prepare("INSERT INTO citas (paciente, doctor, fecha, hora, estado) VALUES (?, ?, ?, ?, 'pendiente')");
            $st->bind_param('ssss', $paciente, $doctor, $fecha, $hora);
            $st->execute();
            volver('Cita agendada (pendiente).');
        }
        if ($accion === 'actualizar') {
            $st = $conn->prepare("UPDATE citas SET paciente = ?, doctor = ?, fecha = ?, hora = ?, estado = ? WHERE id = ?");
            $st->bind_param('sssssi', $paciente, $doctor, $fecha, $hora, $estado, $id);
            $st->execute();
            volver("Cita #$id actualizada.");
        }
        if ($accion === 'eliminar') {
            $st = $conn->prepare("DELETE FROM citas WHERE id = ?");
            $st->bind_param('i', $id);
            $st->execute();
            volver("Cita #$id eliminada.");
        }
    } catch (mysqli_sql_exception $e) {
        volver('Error de base de datos: ' . $e->getMessage(), 'error');
    }
    volver('Acción no válida.', 'error');
}

// ---------- Consulta (GET) ----------
$filtro = $_GET['estado'] ?? '';
if ($filtro !== '' && in_array($filtro, $ESTADOS, true)) {
    $st = $conn->prepare("SELECT * FROM citas WHERE estado = ? ORDER BY fecha, hora");
    $st->bind_param('s', $filtro);
    $st->execute();
    $citas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $filtro = '';
    $citas = $conn->query("SELECT * FROM citas ORDER BY fecha, hora")->fetch_all(MYSQLI_ASSOC);
}

// ¿Se está editando una cita?
$editar = null;
if (isset($_GET['editar'])) {
    $eid = (int)$_GET['editar'];
    $st = $conn->prepare("SELECT * FROM citas WHERE id = ?");
    $st->bind_param('i', $eid);
    $st->execute();
    $editar = $st->get_result()->fetch_assoc();
}
$f = $editar ?? ['id' => 0, 'paciente' => '', 'doctor' => '', 'fecha' => '', 'hora' => '', 'estado' => 'pendiente'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sistema de Citas Médicas</title>
<style>
  body { font-family: system-ui, Arial, sans-serif; margin: 0; background: #f3f6fa; color: #1d2733; }
  header { background: #1f5fa8; color: #fff; padding: 16px 28px; }
  header h1 { margin: 0; font-size: 22px; }
  header small { opacity: .85; }
  main { max-width: 1000px; margin: 24px auto; padding: 0 16px; }
  .card { background: #fff; border-radius: 10px; padding: 18px 22px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  h2 { margin-top: 0; font-size: 18px; }
  form.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; align-items: end; }
  label { font-size: 13px; display: block; margin-bottom: 4px; color: #4a5a6c; }
  input, select { width: 100%; padding: 8px; border: 1px solid #c8d2dd; border-radius: 6px; box-sizing: border-box; }
  button, .btn { padding: 8px 14px; border: 0; border-radius: 6px; cursor: pointer; background: #1f5fa8; color: #fff; text-decoration: none; font-size: 14px; display: inline-block; }
  .btn.gris { background: #7a8794; } .btn.rojo { background: #c0392b; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { padding: 9px 8px; border-bottom: 1px solid #e3e9ef; text-align: left; }
  th { background: #eef3f8; }
  .estado { padding: 3px 9px; border-radius: 12px; font-size: 12px; font-weight: 600; }
  .pendiente { background: #fff3cd; color: #8a6d00; } .confirmada { background: #d6e9ff; color: #1f5fa8; }
  .atendida { background: #d4f4dd; color: #1e7b3c; } .cancelada { background: #f8d7da; color: #a12b36; }
  .msg { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
  .msg.ok { background: #d4f4dd; } .msg.error { background: #f8d7da; }
  .acciones { display: flex; gap: 6px; } .acciones form { margin: 0; }
</style>
</head>
<body>
<header>
  <h1>Sistema de Citas Médicas</h1>
  <small>ASI · Práctica 5 · Equipo 7 — Cliente → Apache/PHP → MariaDB</small>
</header>
<main>

<?php if (!empty($_GET['msg'])): ?>
  <div class="msg <?= ($_GET['tipo'] ?? '') === 'error' ? 'error' : 'ok' ?>"><?= h($_GET['msg']) ?></div>
<?php endif; ?>

<div class="card">
  <h2><?= $editar ? 'Editar cita #' . h($f['id']) : 'Agendar nueva cita' ?></h2>
  <form method="post" class="grid">
    <input type="hidden" name="accion" value="<?= $editar ? 'actualizar' : 'crear' ?>">
    <input type="hidden" name="id" value="<?= h($f['id']) ?>">
    <div><label>Paciente</label><input name="paciente" required maxlength="100" value="<?= h($f['paciente']) ?>"></div>
    <div><label>Doctor</label><input name="doctor" required maxlength="100" value="<?= h($f['doctor']) ?>"></div>
    <div><label>Fecha</label><input type="date" name="fecha" required value="<?= h($f['fecha']) ?>"></div>
    <div><label>Hora</label><input type="time" name="hora" required value="<?= h(substr($f['hora'], 0, 5)) ?>"></div>
    <?php if ($editar): ?>
      <div><label>Estado</label>
        <select name="estado">
          <?php foreach ($ESTADOS as $e): ?>
            <option value="<?= $e ?>" <?= $f['estado'] === $e ? 'selected' : '' ?>><?= ucfirst($e) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div>
      <button type="submit"><?= $editar ? 'Guardar cambios' : 'Agendar' ?></button>
      <?php if ($editar): ?><a class="btn gris" href="index.php">Cancelar</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>Citas registradas (<?= count($citas) ?>)</h2>
  <form method="get" style="margin-bottom:12px; display:flex; gap:8px; align-items:center;">
    <label style="margin:0">Filtrar por estado:</label>
    <select name="estado" onchange="this.form.submit()" style="width:auto">
      <option value="">Todas</option>
      <?php foreach ($ESTADOS as $e): ?>
        <option value="<?= $e ?>" <?= $filtro === $e ? 'selected' : '' ?>><?= ucfirst($e) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <table>
    <tr><th>#</th><th>Paciente</th><th>Doctor</th><th>Fecha</th><th>Hora</th><th>Estado</th><th>Acciones</th></tr>
    <?php if (!$citas): ?>
      <tr><td colspan="7" style="text-align:center;color:#7a8794">No hay citas<?= $filtro ? " con estado $filtro" : '' ?>.</td></tr>
    <?php endif; ?>
    <?php foreach ($citas as $c): ?>
      <tr>
        <td><?= h($c['id']) ?></td>
        <td><?= h($c['paciente']) ?></td>
        <td><?= h($c['doctor']) ?></td>
        <td><?= h($c['fecha']) ?></td>
        <td><?= h(substr($c['hora'], 0, 5)) ?></td>
        <td><span class="estado <?= h($c['estado']) ?>"><?= h($c['estado']) ?></span></td>
        <td class="acciones">
          <a class="btn" href="index.php?editar=<?= h($c['id']) ?>">Editar</a>
          <form method="post" onsubmit="return confirm('¿Eliminar la cita #<?= h($c['id']) ?>?')">
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="id" value="<?= h($c['id']) ?>">
            <button class="btn rojo">Eliminar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

</main>
</body>
</html>
