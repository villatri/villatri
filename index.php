<?php
// Incluir configuración de la base de datos
include '../includes/config.php';

// Iniciar sesión
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

// Determinar el número total de anuncios
$queryTotal = "
    SELECT COUNT(*) as total 
    FROM anuncios 
    WHERE usuario_id = ?";
$stmtTotal = $conn->prepare($queryTotal);
$stmtTotal->bind_param('i', $usuario_id);
$stmtTotal->execute();
$resultTotal = $stmtTotal->get_result();
$rowTotal = $resultTotal->fetch_assoc();
$totalAnuncios = $rowTotal['total'];

// Definir el número de anuncios por página
$anunciosPorPagina = 5;
$totalPaginas = ceil($totalAnuncios / $anunciosPorPagina);

// Obtener el número de página actual de la URL
$paginaActual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($paginaActual < 1) {
    $paginaActual = 1;
} elseif ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

// Determinar el límite inicial para la consulta SQL
$inicio = ($paginaActual - 1) * $anunciosPorPagina;

// Consulta para obtener los anuncios del usuario junto con la imagen principal con paginación
$query = "
    SELECT a.*, i.url_imagen AS imagen_principal, c.nombre AS categoria
    FROM anuncios a
    LEFT JOIN imagenes_anuncios i ON a.id = i.anuncio_id AND i.principal = 1
    LEFT JOIN categorias c ON a.categoria_id = c.id
    WHERE a.usuario_id = ?
    LIMIT ?, ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('iii', $usuario_id, $inicio, $anunciosPorPagina);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Mis Anuncios</title>
    <link href="../assets/css/styles.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }
        .card .img-container {
            flex: 1;
            max-width: 40%;
            padding: 10px;
        }
        .card .img-container img {
            width: 100%;
            height: auto;
            max-height: 150px; /* Ajusta esta altura según sea necesario */
            border-radius: 5px;
            object-fit: cover;
        }
        .card .content-container {
            flex: 2;
            padding: 10px;
        }
        .card .footer-container {
            flex-basis: 100%;
            text-align: right;
            padding: 10px;
            border-top: 1px solid #ddd;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container mt-5">
        <h2 class="mb-4">Mis Anuncios</h2>
        <a href="publicar.php" class="btn btn-success mb-3">Crear Nuevo Anuncio</a>
        <?php while ($anuncio = $result->fetch_assoc()): ?>
            <div class="card mb-3 w-100">
                <div class="row g-0">
                    <div class="col-md-2 ">
                        <img src="<?= '../' . htmlspecialchars($anuncio['imagen_principal']); ?>"  class="img-fluid rounded-start"
                             alt="" style="width: 240px; height: 260px;" >
                    </div>
                    <div class="col-md-10">
                        <div class="card-body">
                            <p><strong>Categoría:</strong> <?= htmlspecialchars($anuncio['categoria'] ?? 'Sin categoría'); ?></p>
                            <h5 class="card-title"><?= htmlspecialchars($anuncio['titulo']); ?></h5>
                            <p class="card-text"><?= htmlspecialchars(mb_strimwidth($anuncio['descripcion'], 0, 150, '...')); ?></p>
                        </div>

                    </div>
                </div>
                <div class="card-footer text-end">
                     <a href="validar_anuncio.php?id=<?= $anuncio['id']; ?>" class="btn btn-info btn-sm me-2">Validar</a>
                     <a href="promover_anuncio.php?id=<?= $anuncio['id']; ?>" class="btn btn-success btn-sm me-2">Promover</a>
                     <a href="editar_anuncio.php?id=<?= $anuncio['id']; ?>" class="btn btn-primary btn-sm me-2">Editar</a>
                     <a href="eliminar_anuncio.php?id=<?= $anuncio['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Estás seguro de eliminar este anuncio?')">Eliminar</a>
                     
                </div>
            </div>
        <?php endwhile; ?>
    </div>

        <!-- Paginación -->
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $paginaActual == 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?pagina=<?= $paginaActual - 1 ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                    <li class="page-item <?= $i == $paginaActual ? 'active' : '' ?>">
                        <a class="page-link" href="?pagina=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $paginaActual == $totalPaginas ? 'disabled' : '' ?>">
                    <a class="page-link" href="?pagina=<?= $paginaActual + 1 ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <?php include '../includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
