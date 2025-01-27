<?php
// Conexión a la base de datos y configuraciones iniciales
include '../includes/config.php';
include 'functions.php';

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

// Validar ID del anuncio
if (!isset($_GET['id'])) {
    die("ID de anuncio no proporcionado");
}
$anuncio_id = intval($_GET['id']);

// Obtener datos del anuncio
$queryAnuncio = "SELECT * FROM anuncios WHERE id = ? AND usuario_id = ?";
$stmtAnuncio = $conn->prepare($queryAnuncio);
$stmtAnuncio->bind_param('ii', $anuncio_id, $usuario_id);
$stmtAnuncio->execute();
$resultAnuncio = $stmtAnuncio->get_result();

if ($resultAnuncio->num_rows === 0) {
    die("Anuncio no encontrado o no tienes permiso para editarlo");
}

$anuncio = $resultAnuncio->fetch_assoc();

// consulta para obtener el email del usuario
$queryUsuario = "SELECT email FROM usuarios WHERE id = ?";
$stmtUsuario = $conn->prepare($queryUsuario);
$stmtUsuario->bind_param('i', $usuario_id);
$stmtUsuario->execute();
$resultUsuario = $stmtUsuario->get_result();
$usuario = $resultUsuario->fetch_assoc();
$emailUsuario = $usuario['email'];

// Obtener imágenes del anuncio
$queryImagenes = "SELECT * FROM imagenes_anuncios WHERE anuncio_id = ?";
$stmtImagenes = $conn->prepare($queryImagenes);
$stmtImagenes->bind_param('i', $anuncio_id);
$stmtImagenes->execute();
$resultImagenes = $stmtImagenes->get_result();
$imagenesAnuncio = $resultImagenes->fetch_all(MYSQLI_ASSOC);

// Obtener ciudades y categorías
$queryCiudades = "SELECT id, nombre FROM ciudades ORDER BY nombre";
$resultCiudades = $conn->query($queryCiudades);

$queryCategorias = "SELECT id, nombre FROM categorias";
$resultCategorias = $conn->query($queryCategorias);

// Manejar solicitud AJAX para comunas
if (isset($_GET['ciudad_id'])) {
    $ciudad_id = intval($_GET['ciudad_id']);
    $query = "SELECT id, nombre FROM comunas WHERE ciudad_id = ? ORDER BY nombre";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $ciudad_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $comunas = [];
    while ($row = $result->fetch_assoc()) {
        $comunas[] = $row;
    }
    echo json_encode($comunas);
    exit;
}

// Procesamiento del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();

        // Sanitización y validación de datos básicos
        $categoria_id = intval($_POST['categoria_id']);
        $comuna_id = intval($_POST['comuna_id']);
        $ciudad_id = intval($_POST['ciudad_id']);
        $edad = intval($_POST['edad']);
        $titulo = htmlspecialchars(trim($_POST['titulo']));
        $descripcion = htmlspecialchars(trim($_POST['descripcion']));
        $telefono = htmlspecialchars(trim($_POST['telefono']));
        $whatsapp = isset($_POST['whatsapp']) ? 1 : 0;
        $correo = filter_var(trim($_POST['correo']), FILTER_SANITIZE_EMAIL);

        // Validaciones básicas
        if (empty($titulo) || empty($descripcion) || empty($telefono) || empty($correo)) {
            throw new Exception("Todos los campos son obligatorios");
        }

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Correo electrónico inválido");
        }

        // Actualizar datos del anuncio
// Dentro del bloque try del procesamiento POST
$query = "UPDATE anuncios SET 
          categoria_id = ?, comuna_id = ?, ciudad_id = ?, edad = ?, 
          titulo = ?, descripcion = ?, telefono = ?, whatsapp = ?, 
          correo_electronico = ? 
          WHERE id = ? AND usuario_id = ?";

$stmt = $conn->prepare($query);
$whatsapp = isset($_POST['whatsapp']) ? 1 : 0;

// Usar $emailUsuario en lugar de $_POST['correo']
$stmt->bind_param('iiiisssisii', 
    $_POST['categoria_id'],
    $_POST['comuna_id'],
    $_POST['ciudad_id'],
    $_POST['edad'],
    $_POST['titulo'],
    $_POST['descripcion'],
    $_POST['telefono'],
    $whatsapp,
    $emailUsuario,  // Aquí usamos el email del usuario en lugar de $_POST['correo']
    $anuncio_id,
    $usuario_id
);
        $stmt->execute();

        // Procesar imágenes eliminadas
        $imagenesEliminadas = json_decode($_POST['imagenes_eliminadas'], true);
        foreach ($imagenesEliminadas as $imagenId) {
            $querySelectImage = "SELECT url_imagen FROM imagenes_anuncios WHERE id = ? AND anuncio_id = ?";
            $stmtSelectImage = $conn->prepare($querySelectImage);
            $stmtSelectImage->bind_param('ii', $imagenId, $anuncio_id);
            $stmtSelectImage->execute();
            $resultSelectImage = $stmtSelectImage->get_result();
            
            if ($row = $resultSelectImage->fetch_assoc()) {
                $imagePath = $row['url_imagen'];
                
                // Eliminar registro de la base de datos
                $queryDeleteImage = "DELETE FROM imagenes_anuncios WHERE id = ? AND anuncio_id = ?";
                $stmtDeleteImage = $conn->prepare($queryDeleteImage);
                $stmtDeleteImage->bind_param('ii', $imagenId, $anuncio_id);
                $stmtDeleteImage->execute();

                // Eliminar archivo físico
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
        }

        // Procesar imágenes nuevas
        if (!empty($_FILES['imagenes']['tmp_name'][0])) {
            $imagenesNuevas = [];
            $imagenesHash = []; // Array para control de duplicados

            foreach ($_FILES['imagenes']['tmp_name'] as $index => $tmpName) {
                if ($tmpName && is_uploaded_file($tmpName)) {
                    // Calcular hash del archivo
                    $fileHash = md5_file($tmpName);
                    
                    // Verificar duplicado en memoria
                    if (in_array($fileHash, $imagenesHash)) {
                        continue;
                    }

                    // Verificar duplicado en base de datos
                    $queryCheckHash = "SELECT COUNT(*) as count FROM imagenes_anuncios 
                                     WHERE anuncio_id = ? AND hash = ?";
                    $stmtCheckHash = $conn->prepare($queryCheckHash);
                    $stmtCheckHash->bind_param('is', $anuncio_id, $fileHash);
                    $stmtCheckHash->execute();
                    $resultHash = $stmtCheckHash->get_result();
                    if ($resultHash->fetch_assoc()['count'] > 0) {
                        continue;
                    }

                    // Validaciones de archivo
                    if ($_FILES['imagenes']['size'][$index] > 5 * 1024 * 1024) {
                        continue;
                    }

                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                    if (!in_array($_FILES['imagenes']['type'][$index], $allowedTypes)) {
                        continue;
                    }

                    // Procesar imagen
                    $nombreArchivo = uniqid() . '_' . basename($_FILES['imagenes']['name'][$index]);
                    $rutaDestino = '../uploads/' . $nombreArchivo;

                    if (move_uploaded_file($tmpName, $rutaDestino)) {
                        // Agregar marca de agua
                        addTextWatermark($rutaDestino, "INFOESCORT.CL", $rutaDestino);
                        
                        $imagenesHash[] = $fileHash;
                        $imagenesNuevas[] = [
                            'ruta' => $rutaDestino,
                            'principal' => 0,
                            'hash' => $fileHash
                        ];
                    }
                }
            }

            // Insertar imágenes nuevas
            if (!empty($imagenesNuevas)) {
                $queryImg = "INSERT INTO imagenes_anuncios (anuncio_id, url_imagen, principal, hash) 
                            VALUES (?, ?, ?, ?)";
                $stmtImg = $conn->prepare($queryImg);

                foreach ($imagenesNuevas as $imagen) {
                    $stmtImg->bind_param('isis', 
                        $anuncio_id, 
                        $imagen['ruta'], 
                        $imagen['principal'],
                        $imagen['hash']
                    );
                    $stmtImg->execute();
                }
            }
        }

        // Actualizar imagen principal
        if (isset($_POST['imagen_principal']) && !empty($_POST['imagen_principal'])) {
            $imagen_principal_id = intval($_POST['imagen_principal']);
            
            // Resetear todas las imágenes como no principales
            $queryResetMain = "UPDATE imagenes_anuncios SET principal = 0 
                             WHERE anuncio_id = ?";
            $stmtResetMain = $conn->prepare($queryResetMain);
            $stmtResetMain->bind_param('i', $anuncio_id);
            $stmtResetMain->execute();

            // Establecer la nueva imagen principal
            $querySetMain = "UPDATE imagenes_anuncios SET principal = 1 
                           WHERE id = ? AND anuncio_id = ?";
            $stmtSetMain = $conn->prepare($querySetMain);
            $stmtSetMain->bind_param('ii', $imagen_principal_id, $anuncio_id);
            $stmtSetMain->execute();
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Anuncio actualizado correctamente']);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modificar Anuncio</title>
    
<style>
    /* Estilos generales */
    .container {
        max-width: 1200px;
        padding: 20px;
    }

    /* Boxes con mejor diseño */
    .box {
        background-color: #ffffff;
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
        padding: 25px;
        margin-bottom: 25px;
    }

    .box:hover {
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }

    /* Títulos de secciones */
    .box h4 {
        color: #2c3e50;
        border-bottom: 2px solid #e9ecef;
        padding-bottom: 10px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    /* Mejoras en el contenedor de subida de imágenes */
    .image-upload-container {
        border: 2px dashed #007bff;
        background-color: #f8f9fa;
        border-radius: 10px;
        padding: 30px;
        text-align: center;
        cursor: pointer;
        margin-bottom: 20px;
        transition: all 0.3s ease;
    }

    .image-upload-container:hover {
        border-color: #0056b3;
        background-color: #e9ecef;
    }

    .image-upload-container.dragover {
        border-color: #28a745;
        background-color: rgba(40, 167, 69, 0.1);
    }

    .image-upload-container p {
        color: #6c757d;
        margin: 0;
    }

    /* Grid de imágenes */
    .image-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 20px;
        padding: 10px;
    }

    /* Items de imagen */
    .image-item {
        position: relative;
        width: 100%;
        padding-top: 100%;
        background-color: #f8f9fa;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
    }

    .image-item:hover {
        transform: scale(1.02);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    .image-item img {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    /* Botones de imagen - siempre visibles */
    .image-item .mark-main,
    .image-item .remove-image {
        position: absolute;
        padding: 8px 12px;
        border: none;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        opacity: 1;
    }

    .image-item .mark-main {
        left: 8px;
        bottom: 8px;
        background-color: rgba(0, 123, 255, 0.9);
        color: white;
    }

    .image-item .remove-image {
        right: 8px;
        bottom: 8px;
        background-color: rgba(220, 53, 69, 0.9);
        color: white;
    }

    .image-item .mark-main:hover {
        background-color: #0056b3;
        transform: scale(1.05);
    }

    .image-item .remove-image:hover {
        background-color: #c82333;
        transform: scale(1.05);
    }

    .image-item .mark-main.active {
        background-color: #28a745;
    }

    /* Inputs y selects */
    .form-control {
        border-radius: 6px;
        border: 1px solid #ced4da;
        padding: 10px 15px;
        transition: all 0.3s ease;
    }

    .form-control:focus {
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }

    /* Switch de WhatsApp */
    .custom-switch .custom-control-label::before {
        height: 1.5rem;
        width: 2.75rem;
        border-radius: 1rem;
    }

    .custom-switch .custom-control-input:checked ~ .custom-control-label::before {
        background-color: #28a745;
        border-color: #28a745;
    }

    /* Botón de submit */
    .btn-submit {
        background-color: #007bff;
        color: white;
        padding: 12px 30px;
        border-radius: 6px;
        font-weight: 600;
        transition: all 0.3s ease;
        margin-top: 20px;
        width: 100%;
    }

    .btn-submit:hover {
        background-color: #0056b3;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    /* Loading spinner */
    #loading {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.9);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
    }

    .spinner-border {
        width: 3rem;
        height: 3rem;
    }

    /* Mensajes de validación */
    .invalid-feedback {
        font-size: 0.85rem;
        color: #dc3545;
        margin-top: 5px;
    }

    /* Tooltips */
    [data-tooltip] {
        position: relative;
        cursor: help;
    }

    [data-tooltip]:before {
        content: attr(data-tooltip);
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        padding: 5px 10px;
        background-color: #333;
        color: white;
        border-radius: 4px;
        font-size: 12px;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    [data-tooltip]:hover:before {
        opacity: 1;
        visibility: visible;
    }

    /* Ajustes para textareas */
    textarea.form-control {
        min-height: 120px;
        resize: vertical;
    }

    /* Estilos para labels */
    label {
        font-weight: 500;
        color: #495057;
        margin-bottom: 0.5rem;
    }

    /* Estilos para select */
    select.form-control {
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 8 8'%3E%3Cpath fill='%23343a40' d='M2.5 0L1 1.5 3.5 4 1 6.5 2.5 8l4-4-4-4z' transform='rotate(90 4 4)'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 1rem center;
        background-size: 8px 8px;
        padding-right: 2.5rem;
    }

    /* Ajustes responsivos */
    @media (max-width: 768px) {
        .image-grid {
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }

        .box {
            padding: 15px;
        }

        .form-control {
            font-size: 16px;
        }

        .image-item .mark-main,
        .image-item .remove-image {
            padding: 6px 10px;
            font-size: 11px;
        }
    }

    @media (max-width: 576px) {
        .container {
            padding: 10px;
        }

        .box {
            padding: 15px;
            margin-bottom: 15px;
        }

        .image-grid {
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
        }
    }
/* Estilos para los botones con iconos */

.image-item .mark-main {
    left: 10px;
    color: #6c757d;
}

.image-item .mark-main:hover {
    background-color: rgba(0, 0, 0, 0.7);
}

.image-item .mark-main.active {
    color: #ffffff;
}

.image-item .remove-image {
    right: 10px;
    color: #ffffff;
    background-color: rgba(220, 53, 69, 0.8);
}

.image-item .remove-image:hover {
    background-color: rgba(220, 53, 69, 1);
}

/* Efecto hover para mostrar los botones */
.image-item button {
    opacity: 0;
    transform: scale(0.8);
}

.image-item:hover button {
    opacity: 1;
    transform: scale(1);
}

/* Mantener visible el botón de principal si está activo */
.image-item .mark-main.active {
    opacity: 1;
    transform: scale(1);
}

/* Asegurar que los iconos tengan el tamaño correcto */
.image-item button i {
    font-size: 16px;
}




.image-item {
    position: relative;
    width: 100%;
    padding-top: 100%;
    background-color: #f8f9fa;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 15px;
    transition: all 0.3s ease;
}

.image-item img {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.image-item button {
    position: absolute;
    bottom: 10px;
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background-color: rgba(0, 0, 0, 0.5);
    opacity: 0;
    transform: scale(0.8);
}

.image-item:hover button {
    opacity: 1;
    transform: scale(1);
}

.image-item .mark-main {
    left: 10px;
    color: #6c757d;
}

.image-item .mark-main:hover {
    background-color: rgba(0, 0, 0, 0.7);
}

.image-item .mark-main.active {
    color: #ffffff;
    opacity: 1;
    transform: scale(1);
}

.image-item .remove-image {
    right: 10px;
    color: #ffffff;
    background-color: rgba(220, 53, 69, 0.8);
}

.image-item .remove-image:hover {
    background-color: rgba(220, 53, 69, 1);
}

.image-item button i {
    font-size: 16px;
}

.image-item[data-main="true"] {
    border: 4px solid #28a745; /* Borde verde */
    box-shadow: 0 0 10px rgba(40, 167, 69, 0.5); /* Brillo verde */
}

</style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container my-5">
        <h1 class="text-center mb-4">Modificar Anuncio</h1>
        <form action="" method="POST" enctype="multipart/form-data" id="modificarForm">
            <!-- Campos ocultos -->
            <input type="hidden" name="imagen_principal" id="imagen_principal" value="">
            <input type="hidden" name="imagenes_eliminadas" id="imagenes_eliminadas" value="[]">

<!-- Box 1: Categoría, Ciudad y Comuna -->
<div class="box mb-4 p-4 border rounded shadow-sm">
    <h4 class="mb-3">Información del Anuncio</h4>
    
    <!-- Categoría -->
    <div class="form-group mb-4">
        <label for="categoria_id" class="form-label">Categoría *</label>
        <select name="categoria_id" id="categoria_id" class="form-control" required>
            <option value="" disabled selected>Selecciona una categoría</option>
            <?php while ($categoria = $resultCategorias->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($categoria['id']); ?>"
                        <?= isset($anuncio['categoria_id']) && $categoria['id'] == $anuncio['categoria_id'] ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($categoria['nombre']); ?>
                </option>
            <?php endwhile; ?>
        </select>
        <div class="invalid-feedback">Por favor, selecciona una categoría.</div>
    </div>

    <!-- Ciudad y Comuna en grid -->
    <div class="row g-3"> <!-- g-3 agrega un espaciado entre columnas -->
        <div class="col-md-6">
            <div class="form-group">
                <label for="ciudad_id" class="form-label">Ciudad *</label>
                <select name="ciudad_id" id="ciudad_id" class="form-control" onchange="filtrarComunas()" required>
                    <option value="" disabled selected>Selecciona una ciudad</option>
                    <?php while ($ciudad = $resultCiudades->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($ciudad['id']); ?>"
                                <?= isset($anuncio['ciudad_id']) && $ciudad['id'] == $anuncio['ciudad_id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($ciudad['nombre']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <div class="invalid-feedback">Por favor, selecciona una ciudad.</div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="form-group">
                <label for="comuna_id" class="form-label">Comuna *</label>
                <select name="comuna_id" id="comuna_id" class="form-control" required>
                    <option value="" disabled selected>Primero selecciona una ciudad</option>
                    <?php if (isset($anuncio['ciudad_id'])): ?>
                        <?php
                        $queryComunas = "SELECT id, nombre FROM comunas WHERE ciudad_id = ? ORDER BY nombre";
                        $stmtComunas = $conn->prepare($queryComunas);
                        $stmtComunas->bind_param('i', $anuncio['ciudad_id']);
                        $stmtComunas->execute();
                        $resultComunas = $stmtComunas->get_result();
                        while ($comuna = $resultComunas->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($comuna['id']); ?>"
                                    <?= isset($anuncio['comuna_id']) && $comuna['id'] == $anuncio['comuna_id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($comuna['nombre']); ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
                <div class="invalid-feedback">Por favor, selecciona una comuna.</div>
            </div>
        </div>
    </div>
</div>

<!-- Box 2: Edad, Título y Descripción -->
<div class="box mb-4 p-4 border rounded shadow-sm">
    <h4 class="mb-3">Detalles del Anuncio</h4>
    
    <!-- Edad -->
    <div class="mb-4">
        <div class="col-md-2 px-0">
            <label for="edad" class="form-label">Edad *</label>
            <input type="number" 
                   name="edad" 
                   id="edad" 
                   class="form-control" 
                   min="18" 
                   max="99" 
                   required
                   placeholder="Edad"
                   value="<?= isset($anuncio['edad']) ? htmlspecialchars($anuncio['edad']) : ''; ?>">
            <div class="invalid-feedback">La edad debe estar entre 18 y 99 años</div>
        </div>
    </div>

    <!-- Título -->
    <div class="form-group mb-4">
        <label for="titulo" class="form-label d-flex justify-content-between align-items-center">
            <span>Título del Anuncio *</span>
        </label>
        <input type="text" 
               name="titulo" 
               id="titulo" 
               class="form-control"
               minlength="40"
               maxlength="70"
               required
               placeholder="Escribe un título atractivo para tu anuncio"
               value="<?= isset($anuncio['titulo']) ? htmlspecialchars($anuncio['titulo']) : ''; ?>">
        <div class="invalid-feedback">El título debe tener entre 40 y 70 caracteres</div>
        <div class="form-text text-end" id="tituloCaracteres">0 caracteres (mínimo 40)</div>
    </div>

    <!-- Descripción -->
    <div class="form-group mb-3">
        <label for="descripcion" class="form-label d-flex justify-content-between align-items-center">
            <span>Descripción *</span>
        </label>
        <textarea name="descripcion" 
                  id="descripcion" 
                  class="form-control"
                  rows="5" 
                  minlength="250"
                  maxlength="1000"
                  required
                  placeholder="Describe detalladamente tu servicio..."
        ><?= isset($anuncio['descripcion']) ? htmlspecialchars($anuncio['descripcion']) : ''; ?></textarea>
        <div class="invalid-feedback">La descripción debe tener entre 250 y 1000 caracteres</div>
        <div class="form-text text-end" id="descripcionCaracteres">0 caracteres (mínimo 250)</div>
    </div>
</div>


            <!-- Box 3: Imágenes -->
            <div class="box mb-4 p-4 border rounded shadow-sm">
                <h4 class="mb-3">Imágenes del Anuncio</h4>
                <div class="image-upload-container" id="drop-area">
                    <p class="mb-0">
                        <i class="fas fa-cloud-upload-alt fa-2x mb-2"></i><br>
                        Arrastra y suelta imágenes aquí o haz clic para seleccionar
                    </p>
                    <input type="file" name="imagenes[]" id="imagenes" class="d-none"
                           multiple accept=".jpg,.jpeg,.png,.gif">
                </div>
                <div class="image-grid" id="image-preview">
                    <?php foreach ($imagenesAnuncio as $index => $imagen): ?>
<div class="image-item" data-id="<?= $imagen['id']; ?>">
    <img src="<?= htmlspecialchars($imagen['url_imagen']); ?>" 
         alt="Imagen del anuncio">
    <button type="button" 
            class="mark-main <?= $imagen['principal'] ? 'active' : ''; ?>"
            onclick="setMainImage(<?= $index; ?>)">
        <i class="<?= $imagen['principal'] ? 'fas' : 'far' ?> fa-star"></i>
    </button>
    <button type="button" 
            class="remove-image"
            onclick="removeImage(<?= $index; ?>)">
        <i class="fas fa-trash-alt"></i>
    </button>
</div>

                    <?php endforeach; ?>
                </div>
            </div>
<!-- Box 4: Contacto -->
<div class="box mb-4 p-4 border rounded shadow-sm">
    <h4 class="mb-3">Información de Contacto</h4>
    
    <!-- Teléfono -->
    <div class="form-group mb-4">
        <label for="telefono" class="form-label">Teléfono *</label>
        <input type="text" 
               name="telefono" 
               id="telefono" 
               class="form-control"
               placeholder="+56 9 XXXX XXXX"
               required 
               value="<?= isset($anuncio['telefono']) ? htmlspecialchars($anuncio['telefono']) : ''; ?>">
        <div class="invalid-feedback">Ingresa un número de teléfono válido</div>
        <div class="form-text">Formato: +56 9 XXXX XXXX</div>
    </div>

    <!-- WhatsApp -->
    <div class="form-group mb-4">
        <div class="custom-control custom-switch">
            <input type="checkbox" 
                   class="custom-control-input" 
                   id="whatsapp_switch" 
                   name="whatsapp" 
                   value="1"
                   <?= isset($anuncio['whatsapp']) && $anuncio['whatsapp'] ? 'checked' : ''; ?>>
            <label class="custom-control-label" for="whatsapp_switch">
                <i class="fab fa-whatsapp text-success me-1"></i>
                Activar WhatsApp
            </label>
        </div>
        <small class="text-muted d-block mt-1">Marca esta opción si deseas recibir mensajes por WhatsApp</small>
    </div>

    <!-- Correo Electrónico -->
    <div class="form-group mb-3">
        <label for="correo" class="form-label">Correo Electrónico *</label>
        <input type="email" 
               name="correo" 
               id="correo" 
               class="form-control bg-light" 
               required 
               readonly
               value="<?= htmlspecialchars($emailUsuario); ?>">
    </div>

</div>

            <div class="d-flex gap-2 mt-4 justify-content-center container-sm">
                <button type="submit" class="btn btn-primary btn-sm px-4">
                    <i class="fas fa-save me-2"></i>
                    Guardar
                </button>
                <a href="index.php" class="btn btn-secondary btn-sm px-4">
                    <i class="fas fa-arrow-left me-2"></i>
                    Volver
                </a>
            </div>
        </form>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>

<script>
// Constantes
const MAX_FILES = 8;
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif'];

// Variables globales
let images = [];
let processedFiles = new Set();
let uploadedHashes = new Set(); // Nuevo: para controlar hashes de imágenes

// Inicializar imágenes existentes
<?php foreach ($imagenesAnuncio as $imagen): ?>
    images.push({
        id: <?= $imagen['id']; ?>,
        src: "<?= htmlspecialchars($imagen['url_imagen']); ?>",
        isMain: <?= $imagen['principal'] ? 'true' : 'false'; ?>,
        hash: "<?= $imagen['hash'] ?? ''; ?>",
        processed: true
    });
    <?php if (!empty($imagen['hash'])): ?>
        uploadedHashes.add("<?= $imagen['hash']; ?>");
    <?php endif; ?>
<?php endforeach; ?>

// Funciones de utilidad
function showLoading() {
    const loadingDiv = document.createElement('div');
    loadingDiv.id = 'loading';
    loadingDiv.innerHTML = `
        <div class="spinner-border text-primary" role="status">
            <span class="sr-only">Cargando...</span>
        </div>`;
    document.body.appendChild(loadingDiv);
}

function hideLoading() {
    const loadingDiv = document.getElementById('loading');
    if (loadingDiv) {
        loadingDiv.remove();
    }
}

function calculateFileHash(file) {
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = (e) => {
            const wordArray = CryptoJS.lib.WordArray.create(e.target.result);
            const hash = CryptoJS.MD5(wordArray).toString();
            resolve(hash);
        };
        reader.readAsArrayBuffer(file);
    });
}

async function validateFile(file) {
    if (!validateFileSize(file)) {
        return false;
    }
    if (!validateFileType(file)) {
        return false;
    }

    // Verificar hash del archivo
    const hash = await calculateFileHash(file);
    if (uploadedHashes.has(hash)) {
        alert('Esta imagen ya ha sido subida anteriormente.');
        return false;
    }
    uploadedHashes.add(hash);
    return true;
}

function validateFileSize(file) {
    if (file.size > MAX_FILE_SIZE) {
        alert(`El archivo ${file.name} es demasiado grande. Máximo 5MB permitido.`);
        return false;
    }
    return true;
}

function validateFileType(file) {
    if (!ALLOWED_TYPES.includes(file.type)) {
        alert(`El archivo ${file.name} no es un tipo de imagen permitido.`);
        return false;
    }
    return true;
}

// Manejo de imágenes
async function handleFiles(files) {
    if (images.length + files.length > MAX_FILES) {
        alert(`Solo puedes subir un máximo de ${MAX_FILES} imágenes.`);
        return;
    }

    for (const file of Array.from(files)) {
        const fileId = `${file.name}-${file.size}-${file.lastModified}`;
        
        // Verificar si el archivo ya ha sido procesado
        if (processedFiles.has(fileId)) {
            console.log('Archivo ya procesado:', fileId);
            continue;
        }

        if (await validateFile(file)) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const imgObject = {
                    src: e.target.result,
                    file: file,
                    isMain: images.length === 0,
                    fileId: fileId,
                    processed: false,
                    hash: null // Se establecerá después
                };
                images.push(imgObject);
                processedFiles.add(fileId);
                console.log('Nueva imagen agregada:', imgObject);
                renderImages();
            };
            reader.readAsDataURL(file);
        }
    }
}

function setMainImage(index) {
    const buttons = document.querySelectorAll('.mark-main');
    const imageItems = document.querySelectorAll('.image-item');
    
    buttons.forEach((button, i) => {
        const icon = button.querySelector('i');
        const imageItem = imageItems[i];
        
        if (i === index) {
            button.classList.add('active');
            icon.classList.remove('far');
            icon.classList.add('fas');
            imageItem.setAttribute('data-main', 'true');
            images[i].isMain = true;
        } else {
            button.classList.remove('active');
            icon.classList.remove('fas');
            icon.classList.add('far');
            imageItem.removeAttribute('data-main');
            images[i].isMain = false;
        }
    });
}

// Función auxiliar para eliminar imagen
function removeImage(index) {
    images.splice(index, 1);
    renderImages();
}

function removeImage(index) {
    if (confirm('¿Estás seguro de que deseas eliminar esta imagen?')) {
        const removedImage = images.splice(index, 1)[0];
        
        if (removedImage.id) {
            const eliminadas = JSON.parse(document.getElementById('imagenes_eliminadas').value);
            eliminadas.push(removedImage.id);
            document.getElementById('imagenes_eliminadas').value = JSON.stringify(eliminadas);
        }
        
        if (removedImage.fileId) {
            processedFiles.delete(removedImage.fileId);
        }
        
        if (removedImage.hash) {
            uploadedHashes.delete(removedImage.hash);
        }
        
        if (images.length > 0 && !images.some(img => img.isMain)) {
            images[0].isMain = true;
        }
        
        renderImages();
        updateMainImageInput();
        
        console.log('Imagen eliminada:', removedImage);
        console.log('Imágenes restantes:', images);
    }
}

function renderImages() {
    const previewContainer = document.getElementById('image-preview');
    previewContainer.innerHTML = '';
    
    images.forEach((image, index) => {
        // Crear contenedor de imagen
        const imageItem = document.createElement('div');
        imageItem.classList.add('image-item');
        
        // Agregar ID si existe
        if (image.id) {
            imageItem.dataset.id = image.id;
        }
        
        // Marcar como principal si corresponde
        if (image.isMain) {
            imageItem.setAttribute('data-main', 'true');
        }

        // Crear y configurar la imagen
        const img = document.createElement('img');
        img.src = image.src;
        img.alt = 'Imagen del anuncio';
        imageItem.appendChild(img);

        // Crear botón de marcar como principal
        const markMainButton = document.createElement('button');
        markMainButton.type = 'button';
        markMainButton.classList.add('mark-main');
        if (image.isMain) {
            markMainButton.classList.add('active');
        }
        
        // Crear icono de estrella
        const starIcon = document.createElement('i');
        starIcon.classList.add(image.isMain ? 'fas' : 'far', 'fa-star');
        markMainButton.appendChild(starIcon);
        markMainButton.addEventListener('click', () => setMainImage(index));
        imageItem.appendChild(markMainButton);

        // Crear botón de eliminar
        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.classList.add('remove-image');
        
        // Crear icono de papelera
        const trashIcon = document.createElement('i');
        trashIcon.classList.add('fas', 'fa-trash-alt');
        removeButton.appendChild(trashIcon);
        removeButton.addEventListener('click', () => removeImage(index));
        imageItem.appendChild(removeButton);

        // Agregar el elemento completo al contenedor
        previewContainer.appendChild(imageItem);
    });
}

function updateMainImageInput() {
    const mainImage = images.find(img => img.isMain);
    document.getElementById('imagen_principal').value = mainImage?.id || '';
}

// Event Listeners
const dropArea = document.getElementById('drop-area');
const fileInput = document.getElementById('imagenes');

dropArea.addEventListener('click', () => fileInput.click());

dropArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropArea.classList.add('dragover');
});

dropArea.addEventListener('dragleave', () => {
    dropArea.classList.remove('dragover');
});

dropArea.addEventListener('drop', (e) => {
    e.preventDefault();
    dropArea.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
});

fileInput.addEventListener('change', (e) => {
    handleFiles(e.target.files);
});

// Manejo del formulario
document.getElementById('modificarForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    if (images.length === 0) {
        alert('Debes tener al menos una imagen en el anuncio');
        return;
    }

    if (!images.some(img => img.isMain)) {
        alert('Debes seleccionar una imagen principal');
        return;
    }

    showLoading();

    const formData = new FormData(this);

    // Limpiar imágenes anteriores del FormData
    formData.delete('imagenes[]');

    // Agregar solo las imágenes nuevas no procesadas
    let hasNewImages = false;
    for (const image of images) {
        if (image.file && !image.processed) {
            formData.append('imagenes[]', image.file);
            image.processed = true;
            hasNewImages = true;
            console.log('Agregando nueva imagen:', image.file.name);
        }
    }

    console.log('Número de imágenes nuevas:', formData.getAll('imagenes[]').length);

    try {
        const response = await fetch(this.action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message);
        }

        alert('Anuncio actualizado correctamente');
        window.location.href = 'index.php';
    } catch (error) {
        console.error('Error:', error);
        alert('Error al actualizar el anuncio: ' + error.message);
    } finally {
        hideLoading();
    }
});

// Filtrado de comunas
function filtrarComunas() {
    const ciudadId = document.getElementById('ciudad_id').value;
    const comunaSelect = document.getElementById('comuna_id');

    showLoading();
    comunaSelect.innerHTML = '<option value="" disabled selected>Cargando comunas...</option>';

    fetch(`modificar_anuncio.php?ciudad_id=${ciudadId}`)
        .then(response => response.json())
        .then(data => {
            comunaSelect.innerHTML = '<option value="" disabled selected>Selecciona una comuna</option>';
            data.forEach(comuna => {
                const option = document.createElement('option');
                option.value = comuna.id;
                option.textContent = comuna.nombre;
                comunaSelect.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al cargar las comunas. Por favor, intente nuevamente.');
        })
        .finally(() => {
            hideLoading();
        });
}

// Validación del teléfono
document.getElementById('telefono').addEventListener('input', function() {
    const telefonoInput = this;
    const rawValue = telefonoInput.value.replace(/\D/g, '');
    let formattedValue = '';

    if (rawValue.startsWith('56')) {
        if (rawValue.length === 11) {
            formattedValue = `+56 ${rawValue[2]} ${rawValue.slice(3, 7)} ${rawValue.slice(7)}`;
        } else if (rawValue.length === 10) {
            formattedValue = `+56 ${rawValue[2]} ${rawValue.slice(3, 6)} ${rawValue.slice(6)}`;
        }
    } else if (rawValue.startsWith('9') && rawValue.length === 9) {
        formattedValue = `+56 9 ${rawValue.slice(1, 5)} ${rawValue.slice(5)}`;
    } else if (rawValue.startsWith('2') && rawValue.length === 9) {
        formattedValue = `+56 2 ${rawValue.slice(1, 4)} ${rawValue.slice(4)}`;
    } else {
        formattedValue = rawValue;
    }

    telefonoInput.value = formattedValue;

    const telefonoPattern = /^\+56 (9 \d{4} \d{4}|2 \d{3} \d{4})$/;
    if (telefonoPattern.test(formattedValue)) {
        telefonoInput.setCustomValidity('');
    } else {
        telefonoInput.setCustomValidity('Por favor, ingresa un número de teléfono chileno válido.');
    }
});

// Contador de caracteres para título
document.getElementById('titulo').addEventListener('input', function() {
    const caracteresActuales = this.value.length;
    const contador = document.getElementById('tituloCaracteres');
    contador.textContent = `${caracteresActuales} caracteres (mínimo 40)`;
    
    if (caracteresActuales < 40) {
        contador.classList.add('text-danger');
        contador.classList.remove('text-success');
    } else {
        contador.classList.add('text-success');
        contador.classList.remove('text-danger');
    }
});

// Contador de caracteres para descripción
document.getElementById('descripcion').addEventListener('input', function() {
    const caracteresActuales = this.value.length;
    const contador = document.getElementById('descripcionCaracteres');
    contador.textContent = `${caracteresActuales} caracteres (mínimo 250)`;
    
    if (caracteresActuales < 250) {
        contador.classList.add('text-danger');
        contador.classList.remove('text-success');
    } else {
        contador.classList.add('text-success');
        contador.classList.remove('text-danger');
    }
});

// Inicializar contadores si hay valores existentes
window.addEventListener('load', function() {
    const titulo = document.getElementById('titulo');
    const descripcion = document.getElementById('descripcion');
    
    if (titulo.value) {
        titulo.dispatchEvent(new Event('input'));
    }
    if (descripcion.value) {
        descripcion.dispatchEvent(new Event('input'));
    }
});

// Inicialización
updateMainImageInput();
</script>
