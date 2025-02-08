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

// Función para manejar errores
function handleError($message, $type = 'danger') {
    $_SESSION['message'] = [
        'text' => $message,
        'type' => $type
    ];
}

// Función para obtener estadísticas con caché
function getEstadisticas($conn, $usuario_id) {
    $cache_key = "stats_user_" . $usuario_id;
    $cache_time = 300; // 5 minutos
    
    if (isset($_SESSION[$cache_key]) && (time() - $_SESSION[$cache_key]['time'] < $cache_time)) {
        return $_SESSION[$cache_key]['data'];
    }

    $queryTotal = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activos,
        SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as pendientes
        FROM anuncios WHERE usuario_id = ?";
    
    $stmt = $conn->prepare($queryTotal);
    $stmt->bind_param('i', $usuario_id);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    
    $_SESSION[$cache_key] = [
        'time' => time(),
        'data' => $stats
    ];
    
    return $stats;
}

// Obtener parámetros de filtro
$categoria_filtro = filter_var($_GET['categoria'] ?? '', FILTER_SANITIZE_STRING);
$estado_filtro = isset($_GET['estado']) && $_GET['estado'] !== '' ? filter_var($_GET['estado'], FILTER_VALIDATE_INT) : null;
$ciudad_filtro = filter_var($_GET['ciudad'] ?? '', FILTER_SANITIZE_STRING);
$comuna_filtro = filter_var($_GET['comuna'] ?? '', FILTER_SANITIZE_STRING);
$busqueda = filter_var($_GET['buscar'] ?? '', FILTER_SANITIZE_STRING);

// Construir la consulta base
$whereConditions = ["a.usuario_id = ?"];
$params = [$usuario_id];
$types = 'i';

if (!empty($categoria_filtro)) {
    $whereConditions[] = "a.categoria_id = ?";
    $params[] = $categoria_filtro;
    $types .= 'i';
}

if ($estado_filtro !== null) {
    $whereConditions[] = "a.activo = ?";
    $params[] = $estado_filtro;
    $types .= 'i';
}

if (!empty($ciudad_filtro)) {
    $whereConditions[] = "a.ciudad_id = ?";
    $params[] = $ciudad_filtro;
    $types .= 'i';
}

if (!empty($comuna_filtro)) {
    $whereConditions[] = "a.comuna_id = ?";
    $params[] = $comuna_filtro;
    $types .= 'i';
}

if (!empty($busqueda)) {
    $whereConditions[] = "(a.titulo LIKE ? OR a.descripcion LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $types .= 'ss';
}

$whereClause = implode(' AND ', $whereConditions);

// Obtener estadísticas
$stats = getEstadisticas($conn, $usuario_id);
$totalAnuncios = $stats['total'];
$anunciosActivos = $stats['activos'];
$anunciosPendientes = $stats['pendientes'];

// Definir el número de anuncios por página y calcular la paginación
$anunciosPorPagina = 5;
$totalPaginas = max(1, ceil($totalAnuncios / $anunciosPorPagina));
$paginaActual = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);
$paginaActual = max(1, min($paginaActual, $totalPaginas));
$inicio = ($paginaActual - 1) * $anunciosPorPagina;

// Consulta principal optimizada
$query = "
    SELECT 
        a.*,
        c.nombre AS categoria,
        ci.nombre AS ciudad,
        co.nombre AS comuna,
        DATE_FORMAT(a.creado_en, '%d/%m/%Y') as fecha_formateada,
        (SELECT url_imagen 
         FROM imagenes_anuncios 
         WHERE anuncio_id = a.id AND principal = 1 
         LIMIT 1) as url_imagen
    FROM anuncios a
    LEFT JOIN categorias c ON a.categoria_id = c.id
    LEFT JOIN ciudades ci ON a.ciudad_id = ci.id
    LEFT JOIN comunas co ON a.comuna_id = co.id
    WHERE $whereClause
    ORDER BY a.creado_en DESC
    LIMIT ?, ?";

$params[] = $inicio;
$params[] = $anunciosPorPagina;
$types .= 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Obtener categorías para el filtro
$queryCategorias = "SELECT id, nombre FROM categorias ORDER BY nombre";
$resultCategorias = $conn->query($queryCategorias);

// Obtener ciudades para el filtro
$queryCiudades = "SELECT id, nombre FROM ciudades ORDER BY nombre";
$resultCiudades = $conn->query($queryCiudades);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Mis Anuncios</title>
    <style>
        .card {
            opacity: 0;
            animation: fadeIn 0.5s ease forwards;
            transition: transform 0.2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .img-container {
            height: 260px;
            overflow: hidden;
        }

        .img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: opacity 0.3s ease;
        }

        .img-container img:hover {
            opacity: 0.9;
        }

        .badge {
            font-size: 0.8rem;
            padding: 0.4em 0.8em;
        }

        .stats-card {
            transition: all 0.3s ease;
            color: white;
            background: linear-gradient(135deg, var(--start-color) 0%, var(--end-color) 100%);
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .stats-card.total { --start-color: #3498db; --end-color: #2980b9; }
        .stats-card.activos { --start-color: #2ecc71; --end-color: #27ae60; }
        .stats-card.pendientes { --start-color: #f1c40f; --end-color: #f39c12; }

        .loading-spinner {
            display: none;
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
        }

        .search-box {
            position: relative;
        }

        .search-results {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 1000;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
    /* Estilos base para las cards */
    .stats-card {
        opacity: 1;
        transition: all 0.3s ease;
        color: white;
        background: linear-gradient(135deg, var(--start-color) 0%, var(--end-color) 100%);
        display: block;
        border-radius: 0.25rem;
        margin-bottom: 1rem;
    }
    
    /* Colores para cada tipo de card */
    .stats-card.total { --start-color: #3498db; --end-color: #2980b9; }
    .stats-card.activos { --start-color: #2ecc71; --end-color: #27ae60; }
    .stats-card.pendientes { --start-color: #f1c40f; --end-color: #f39c12; }
    .stats-card.historial { --start-color: #3498db; --end-color: #2980b9; }
    .stats-card.creditos { --start-color: #f1c40f; --end-color: #f39c12; }
    .stats-card.nuevo { --start-color: #2ecc71; --end-color: #27ae60; }
    
    /* Efectos hover */
    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }
    
    /* Estilos para el contenido de las cards */
    .stats-card .card-body {
        padding: 1.5rem;
    }
    
    .stats-card i, 
    .stats-card h5 {
        color: white;
    }
    
    /* Asegurar que los enlaces no tengan el subrayado predeterminado */
    .text-decoration-none {
        text-decoration: none !important;
    }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container mt-5">
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message']['type'] ?> alert-dismissible fade show">
                <?= $_SESSION['message']['text'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <!-- Filtros -->
        <div class="row mb-4">
            <div class="col-md-12">
                <form class="card p-3" method="GET" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <select name="categoria" class="form-select">
                                <option value="">Todas las categorías</option>
                                <?php while ($cat = $resultCategorias->fetch_assoc()): ?>
                                    <option value="<?= $cat['id'] ?>" <?= ($categoria_filtro == $cat['id'] ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($cat['nombre']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="estado" class="form-select">
                                <option value="">Todos los estados</option>
                                <option value="1" <?= ($estado_filtro === 1 ? 'selected' : '') ?>>Activos</option>
                                <option value="0" <?= ($estado_filtro === 0 ? 'selected' : '') ?>>Pendientes</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="ciudad" id="ciudad" class="form-select">
                                <option value="">Todas las ciudades</option>
                                <?php while ($ciudad = $resultCiudades->fetch_assoc()): ?>
                                    <option value="<?= $ciudad['id'] ?>" <?= ($ciudad_filtro == $ciudad['id'] ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($ciudad['nombre']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="comuna" id="comuna" class="form-select">
                                <option value="">Todas las comunas</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <div class="search-box">
                                <input type="text" name="buscar" class="form-control" 
                                       placeholder="Buscar..." 
                                       value="<?= htmlspecialchars($busqueda) ?>">
                                <div class="loading-spinner">
                                    <span class="spinner-border spinner-border-sm"></span>
                                </div>
                                <div class="search-results"></div>
                            </div>
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <a href="historial_creditos.php" class="stats-card historial text-decoration-none">
                    <div class="card-body text-center">
                        <i class="fas fa-history fa-2x mb-2"></i>
                        <h5 class="mb-0">Historial</h5>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="comprar_creditos.php" class="stats-card creditos text-decoration-none">
                    <div class="card-body text-center">
                        <i class="fas fa-coins fa-2x mb-2"></i>
                        <h5 class="mb-0">Comprar Créditos</h5>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="publicar.php" class="stats-card nuevo text-decoration-none">
                    <div class="card-body text-center">
                        <i class="fas fa-plus fa-2x mb-2"></i>
                        <h5 class="mb-0">Crear Nuevo Anuncio</h5>
                    </div>
                </a>
            </div>
        </div>
        <!-- Estadísticas -->
            <!-- Estadísticas -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card total">
                        <div class="card-body text-center">
                            <h5>Total Anuncios</h5>
                            <h3><?= $totalAnuncios ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card activos">
                        <div class="card-body text-center">
                            <h5>Anuncios Activos</h5>
                            <h3><?= $anunciosActivos ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card pendientes">
                        <div class="card-body text-center">
                            <h5>Pendientes</h5>
                            <h3><?= $anunciosPendientes ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card creditos">
                        <div class="card-body text-center">
                            <h5>Mis Créditos</h5>
                            <h3><?= $creditos ?? 0 ?></h3>
                        </div>
                    </div>
                </div>
            </div>

        <!-- Listado de anuncios -->
        <?php if ($result->num_rows === 0): ?>
            <div class="alert alert-info">
                No tienes anuncios publicados. 
                <a href="publicar.php" class="alert-link">¡Publica tu primer anuncio!</a>
            </div>
        <?php else: ?>
            <?php while ($anuncio = $result->fetch_assoc()): ?>
                <div class="card mb-3">
                    <div class="row g-0">
                        <div class="col-md-3">
                            <div class="img-container">
                                <img src="<?= '../uploads/' . htmlspecialchars($anuncio['url_imagen'] ?? 'default.jpg'); ?>" 
                                     class="rounded-start" alt="Imagen del anuncio">
                            </div>
                        </div>
                        <div class="col-md-9">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <span class="badge bg-<?= $anuncio['activo'] ? 'success' : 'warning' ?> mb-2">
                                            <?= $anuncio['activo'] ? 'Activo' : 'Pendiente' ?>
                                        </span>
                                        <?php if ($anuncio['es_top']): ?>
                                            <span class="badge bg-primary mb-2 ms-2">TOP</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        <i class="far fa-calendar-alt"></i> <?= $anuncio['fecha_formateada'] ?>
                                    </small>
                                </div>
                                
                                <h5 class="card-title"><?= htmlspecialchars($anuncio['titulo']); ?></h5>
                                     <p class="card-text">
                                    <?= htmlspecialchars(mb_strimwidth($anuncio['descripcion'], 0, 150, '...')); ?>
                                </p>
                                
                                <div class="mt-2">
                                    <p class="mb-1">
                                        <i class="fas fa-tag me-2"></i>
                                        <strong>Categoría:</strong> <?= htmlspecialchars($anuncio['categoria'] ?? 'Sin categoría') ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-map-marker-alt me-2"></i>
                                        <strong>Ubicación:</strong> 
                                        <?= htmlspecialchars(($anuncio['ciudad'] ?? 'N/A') . ', ' . ($anuncio['comuna'] ?? 'N/A')) ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-phone me-2"></i>
                                        <strong>Contacto:</strong> 
                                        <?= htmlspecialchars($anuncio['telefono']) ?>
                                        <?php if ($anuncio['whatsapp']): ?>
                                            <span class="badge bg-success">
                                                <i class="fab fa-whatsapp"></i> WhatsApp
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <?php if (!$anuncio['activo']): ?>
                            <button type="button" 
                                    class="btn btn-info btn-sm me-2"
                                    onclick="confirmarAccion('¿Deseas validar este anuncio?', () => validarAnuncio(<?= $anuncio['id'] ?>))">
                                <i class="fas fa-check"></i> Validar
                            </button>
                        <?php endif; ?>
                        
                        <?php if (!$anuncio['es_top']): ?>
                            <button type="button" 
                                    class="btn btn-success btn-sm me-2"
                                    onclick="confirmarAccion('¿Deseas promover este anuncio a TOP?', () => promoverAnuncio(<?= $anuncio['id'] ?>))">
                                <i class="fas fa-star"></i> Promover
                            </button>
                        <?php endif; ?>
                        
                        <a href="modificar.php?id=<?= $anuncio['id']; ?>" 
                           class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-edit"></i> Editar
                        </a>
                        <button type="button" 
                                class="btn btn-danger btn-sm"
                                onclick="confirmarAccion('¿Estás seguro de que deseas eliminar este anuncio?', () => eliminarAnuncio(<?= $anuncio['id'] ?>))">
                            <i class="fas fa-trash"></i> Eliminar
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>

            <!-- Paginación -->
            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Navegación de páginas" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $paginaActual == 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= construirURL($paginaActual - 1) ?>" aria-label="Anterior">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                            <li class="page-item <?= $i == $paginaActual ? 'active' : '' ?>">
                                <a class="page-link" href="<?= construirURL($i) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $paginaActual == $totalPaginas ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= construirURL($paginaActual + 1) ?>" aria-label="Siguiente">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal de confirmación -->
    <div class="modal fade" id="confirmarModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar acción</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="confirmarModalTexto"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="confirmarModalBoton">Confirmar</button>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
    function construirURL(pagina) {
        const params = new URLSearchParams(window.location.search);
        params.set('pagina', pagina);
        return `?${params.toString()}`;
    }

    function confirmarAccion(mensaje, callback) {
        const modal = new bootstrap.Modal(document.getElementById('confirmarModal'));
        document.getElementById('confirmarModalTexto').textContent = mensaje;
        document.getElementById('confirmarModalBoton').onclick = () => {
            modal.hide();
            callback();
        };
        modal.show();
    }

    // Función única para cargar comunas
    async function cargarComunas() {
        const ciudadId = document.getElementById('ciudad').value;
        const comunaSelect = document.getElementById('comuna');
        
        // Siempre limpiar y dejar solo la opción por defecto
        comunaSelect.innerHTML = '<option value="">Todas las comunas</option>';
        
        // Si no hay ciudad seleccionada, dejarlo vacío
        if (!ciudadId) {
            return;
        }
        
        // Deshabilitar el select mientras se cargan los datos
        comunaSelect.disabled = true;
        
        try {
            const response = await fetch(`get_comunas.php?ciudad_id=${ciudadId}`);
            if (!response.ok) {
                throw new Error('Error en la respuesta del servidor');
            }

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Error al cargar las comunas');
            }

            // Agregar las comunas solo si hay una ciudad seleccionada
            data.data.forEach(comuna => {
                const option = document.createElement('option');
                option.value = comuna.id;
                option.textContent = comuna.nombre;
                comunaSelect.appendChild(option);
            });

        } catch (error) {
            console.error('Error:', error);
            comunaSelect.innerHTML = '<option value="">Error al cargar comunas</option>';
        } finally {
            comunaSelect.disabled = false;
        }
    }

    // Un solo evento DOMContentLoaded para la inicialización
    document.addEventListener('DOMContentLoaded', function() {
        const ciudadSelect = document.getElementById('ciudad');
        
        // Event listener para cambios en la ciudad
        ciudadSelect.addEventListener('change', cargarComunas);

        // Si hay una ciudad seleccionada al cargar, cargar sus comunas
        if (ciudadSelect.value) {
            cargarComunas();
        }
    });

    // Funciones para las acciones de los anuncios
    async function validarAnuncio(id) {
        try {
            const response = await fetch(`validar_anuncio.php?id=${id}`);
            const data = await response.json();
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Error al validar el anuncio');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error al procesar la solicitud');
        }
    }

    async function promoverAnuncio(id) {
        try {
            const response = await fetch(`promover_anuncio.php?id=${id}`);
            const data = await response.json();
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Error al promover el anuncio');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error al procesar la solicitud');
        }
    }

    async function eliminarAnuncio(id) {
        try {
            const response = await fetch(`eliminar_anuncio.php?id=${id}`);
            const data = await response.json();
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Error al eliminar el anuncio');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error al procesar la solicitud');
        }
    }
    </script>
</body>
</html>                                
