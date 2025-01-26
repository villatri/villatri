<?php
require_once '../includes/config.php';
require_once 'functions.php';

// Clase de validación
class Validator {
    private $errors = [];
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function getErrors() {
        return $this->errors;
    }

    public function hasErrors() {
        return !empty($this->errors);
    }

    // Validar título
    public function validateTitle($title) {
        $title = trim($title);
        if (strlen($title) < 40) {
            $this->errors[] = "El título debe tener al menos 40 caracteres";
            return false;
        }
        if (strlen($title) > 200) {
            $this->errors[] = "El título no puede exceder los 200 caracteres";
            return false;
        }
        if (!preg_match('/^[a-zA-ZáéíóúÁÉÍÓÚñÑ0-9\s.,!¡¿?-]+$/', $title)) {
            $this->errors[] = "El título contiene caracteres no permitidos";
            return false;
        }
        return true;
    }

    // Validar descripción
    public function validateDescription($description) {
        $description = trim($description);
        if (strlen($description) < 250) {
            $this->errors[] = "La descripción debe tener al menos 250 caracteres";
            return false;
        }
        if (strlen($description) > 2000) {
            $this->errors[] = "La descripción no puede exceder los 2000 caracteres";
            return false;
        }
        return true;
    }

    // Validar edad
    public function validateAge($age) {
        $age = intval($age);
        if ($age < 18 || $age > 99) {
            $this->errors[] = "La edad debe estar entre 18 y 99 años";
            return false;
        }
        return true;
    }

    // Validar teléfono
    public function validatePhone($phone) {
        if (!preg_match('/^\+56 (9 \d{4} \d{4}|2 \d{3} \d{4})$/', $phone)) {
            $this->errors[] = "Formato de teléfono inválido";
            return false;
        }
        return true;
    }

    // Validar email
    public function validateEmail($email) {
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = "Correo electrónico inválido";
            return false;
        }
        return true;
    }

    // Validar imágenes
    public function validateImages($files, $existingImagesCount = 0) {
        // Verificar si hay archivos
        if (empty($files['tmp_name'][0]) && $existingImagesCount === 0) {
            $this->errors[] = "Debe incluir al menos una imagen";
            return false;
        }

        // Verificar límite total de imágenes
        $totalImages = count($files['tmp_name']) + $existingImagesCount;
        if ($totalImages > 8) {
            $this->errors[] = "No puede tener más de 8 imágenes en total";
            return false;
        }

        // Validar cada imagen nueva
        foreach ($files['tmp_name'] as $index => $tmpName) {
            if (!empty($tmpName)) {
                // Verificar tamaño (5MB máximo)
                if ($files['size'][$index] > 5 * 1024 * 1024) {
                    $this->errors[] = "La imagen '{$files['name'][$index]}' excede el tamaño máximo permitido (5MB)";
                    return false;
                }

                // Verificar tipo de archivo
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                if (!in_array($files['type'][$index], $allowedTypes)) {
                    $this->errors[] = "El archivo '{$files['name'][$index]}' no es un tipo de imagen permitido";
                    return false;
                }

                // Verificar si es una imagen válida
                if (!getimagesize($tmpName)) {
                    $this->errors[] = "El archivo '{$files['name'][$index]}' no es una imagen válida";
                    return false;
                }
            }
        }

        return true;
    }

    // Validar categoría
    public function validateCategory($categoryId) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM categorias WHERE id = ?");
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->fetch_row()[0] == 0) {
            $this->errors[] = "Categoría inválida";
            return false;
        }
        return true;
    }

    // Validar ciudad y comuna
    public function validateLocation($cityId, $communeId) {
        // Validar ciudad
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM ciudades WHERE id = ?");
        $stmt->bind_param("i", $cityId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()[0] == 0) {
            $this->errors[] = "Ciudad inválida";
            return false;
        }

        // Validar comuna y su relación con la ciudad
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM comunas WHERE id = ? AND ciudad_id = ?");
        $stmt->bind_param("ii", $communeId, $cityId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()[0] == 0) {
            $this->errors[] = "Comuna inválida o no pertenece a la ciudad seleccionada";
            return false;
        }

        return true;
    }
}

// Verificar sesión
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

// Obtener imágenes del anuncio
$queryImagenes = "SELECT * FROM imagenes_anuncios WHERE anuncio_id = ?";
$stmtImagenes = $conn->prepare($queryImagenes);
$stmtImagenes->bind_param('i', $anuncio_id);
$stmtImagenes->execute();
$resultImagenes = $stmtImagenes->get_result();
$imagenesAnuncio = $resultImagenes->fetch_all(MYSQLI_ASSOC);

// Procesar solicitud AJAX para comunas
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

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $validator = new Validator($conn);
        
        // Validar todos los campos
        $isValid = true;
        $isValid &= $validator->validateTitle($_POST['titulo']);
        $isValid &= $validator->validateDescription($_POST['descripcion']);
        $isValid &= $validator->validateAge($_POST['edad']);
        $isValid &= $validator->validatePhone($_POST['telefono']);
        $isValid &= $validator->validateEmail($_POST['correo']);
        $isValid &= $validator->validateCategory($_POST['categoria_id']);
        $isValid &= $validator->validateLocation($_POST['ciudad_id'], $_POST['comuna_id']);

        // Contar imágenes existentes que no serán eliminadas
        $imagenesEliminadas = json_decode($_POST['imagenes_eliminadas'], true) ?? [];
        $queryExistingImages = "SELECT COUNT(*) FROM imagenes_anuncios WHERE anuncio_id = ? AND id NOT IN (" . 
            implode(',', array_map('intval', $imagenesEliminadas)) . ")";
        $stmtExisting = $conn->prepare($queryExistingImages);
        $stmtExisting->bind_param('i', $anuncio_id);
        $stmtExisting->execute();
        $existingImagesCount = $stmtExisting->get_result()->fetch_row()[0];

        $isValid &= $validator->validateImages($_FILES['imagenes'], $existingImagesCount);

        if (!$isValid) {
            throw new Exception(implode("<br>", $validator->getErrors()));
        }

        $conn->begin_transaction();

        // Actualizar datos básicos del anuncio
        $query = "UPDATE anuncios SET 
                  categoria_id = ?, comuna_id = ?, ciudad_id = ?, edad = ?, 
                  titulo = ?, descripcion = ?, telefono = ?, whatsapp = ?, 
                  correo_electronico = ? 
                  WHERE id = ? AND usuario_id = ?";
        
        $stmt = $conn->prepare($query);
        $whatsapp = isset($_POST['whatsapp']) ? 1 : 0;
        
        $stmt->bind_param('iiiisssisii', 
            $_POST['categoria_id'],
            $_POST['comuna_id'],
            $_POST['ciudad_id'],
            $_POST['edad'],
            $_POST['titulo'],
            $_POST['descripcion'],
            $_POST['telefono'],
            $whatsapp,
            $_POST['correo'],
            $anuncio_id,
            $usuario_id
        );
        
        $stmt->execute();

        // Procesar imágenes eliminadas
        if (!empty($imagenesEliminadas)) {
            foreach ($imagenesEliminadas as $imagenId) {
                $querySelectImage = "SELECT url_imagen FROM imagenes_anuncios WHERE id = ? AND anuncio_id = ?";
                $stmtSelectImage = $conn->prepare($querySelectImage);
                $stmtSelectImage->bind_param('ii', $imagenId, $anuncio_id);
                $stmtSelectImage->execute();
                $resultSelectImage = $stmtSelectImage->get_result();
                
                if ($row = $resultSelectImage->fetch_assoc()) {
                    $imagePath = $row['url_imagen'];
                    
                    $queryDeleteImage = "DELETE FROM imagenes_anuncios WHERE id = ? AND anuncio_id = ?";
                    $stmtDeleteImage = $conn->prepare($queryDeleteImage);
                    $stmtDeleteImage->bind_param('ii', $imagenId, $anuncio_id);
                    $stmtDeleteImage->execute();

                    if (file_exists($imagePath)) {
                        unlink($imagePath);
                    }
                }
            }
        }

        // Procesar nuevas imágenes
        if (!empty($_FILES['imagenes']['tmp_name'][0])) {
            $uploadDir = '../uploads/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            foreach ($_FILES['imagenes']['tmp_name'] as $index => $tmpName) {
                if (!empty($tmpName)) {
                    $fileHash = md5_file($tmpName);
                    
                    // Verificar duplicado por hash
                    $queryCheckHash = "SELECT COUNT(*) FROM imagenes_anuncios 
                                     WHERE anuncio_id = ? AND hash = ?";
                    $stmtCheckHash = $conn->prepare($queryCheckHash);
                    $stmtCheckHash->bind_param('is', $anuncio_id, $fileHash);
                    $stmtCheckHash->execute();
                    
                    if ($stmtCheckHash->get_result()->fetch_row()[0] > 0) {
                        continue; // Saltar si es duplicado
                    }

                    $fileName = uniqid() . '_' . basename($_FILES['imagenes']['name'][$index]);
                    $targetPath = $uploadDir . $fileName;

                    if (move_uploaded_file($tmpName, $targetPath)) {
                        // Agregar marca de agua
                        addTextWatermark($targetPath, "INFOESCORT.CL", $targetPath);

                        // Insertar en la base de datos
                        $queryImg = "INSERT INTO imagenes_anuncios (anuncio_id, url_imagen, principal, hash) 
                                   VALUES (?, ?, 0, ?)";
                        $stmtImg = $conn->prepare($queryImg);
                        $stmtImg->bind_param('iss', $anuncio_id, $targetPath, $fileHash);
                        $stmtImg->execute();
                    }
                }
            }
        }

        // Actualizar imagen principal
        if (isset($_POST['imagen_principal']) && !empty($_POST['imagen_principal'])) {
            $imagen_principal_id = intval($_POST['imagen_principal']);
            
            // Resetear todas las imágenes como no principales
            $queryResetMain = "UPDATE imagenes_anuncios SET principal = 0 WHERE anuncio_id = ?";
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

// Obtener datos para los selects
$queryCiudades = "SELECT id, nombre FROM ciudades ORDER BY nombre";
$resultCiudades = $conn->query($queryCiudades);

$queryCategorias = "SELECT id, nombre FROM categorias";
$resultCategorias = $conn->query($queryCategorias);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modificar Anuncio</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
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
                <div class="form-group">
                    <label for="categoria_id">Categoría</label>
                    <select name="categoria_id" id="categoria_id" class="form-control" required>
                        <option value="" disabled>Selecciona una categoría</option>
                        <?php while ($categoria = $resultCategorias->fetch_assoc()): ?>
                            <option value="<?= $categoria['id']; ?>" 
                                    <?= $categoria['id'] == $anuncio['categoria_id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($categoria['nombre']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="ciudad_id">Ciudad</label>
                            <select name="ciudad_id" id="ciudad_id" class="form-control" 
                                    onchange="filtrarComunas()" required>
                                <option value="" disabled>Selecciona una ciudad</option>
                                <?php while ($ciudad = $resultCiudades->fetch_assoc()): ?>
                                    <option value="<?= $ciudad['id']; ?>" 
                                            <?= $ciudad['id'] == $anuncio['ciudad_id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($ciudad['nombre']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="comuna_id">Comuna</label>
                            <select name="comuna_id" id="comuna_id" class="form-control" required>
                                <option value="" disabled>Selecciona una comuna</option>
                                <?php
                                $queryComunas = "SELECT id, nombre FROM comunas WHERE ciudad_id = ?";
                                $stmtComunas = $conn->prepare($queryComunas);
                                $stmtComunas->bind_param('i', $anuncio['ciudad_id']);
                                $stmtComunas->execute();
                                $resultComunas = $stmtComunas->get_result();
                                while ($comuna = $resultComunas->fetch_assoc()): ?>
                                    <option value="<?= $comuna['id']; ?>" 
                                            <?= $comuna['id'] == $anuncio['comuna_id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($comuna['nombre']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Box 2: Edad, Título y Descripción -->
            <div class="box mb-4 p-4 border rounded shadow-sm">
                <h4 class="mb-3">Detalles del Anuncio</h4>
                <div class="form-row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="edad">Edad</label>
                            <input type="number" name="edad" id="edad" class="form-control"
                                   min="18" max="99" required
                                   value="<?= htmlspecialchars($anuncio['edad']); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="titulo" class="d-flex justify-content-between">
                        <span>Título del Anuncio *</span>
                        <small class="text-muted">Mínimo 40 caracteres</small>
                    </label>
                    <input type="text" name="titulo" id="titulo" class="form-control"
                           minlength="40" required
                           value="<?= htmlspecialchars($anuncio['titulo']); ?>">
                </div>

                <div class="form-group">
                    <label for="descripcion" class="d-flex justify-content-between">
                        <span>Descripción *</span>
                        <small class="text-muted">Mínimo 250 caracteres</small>
                    </label>
                    <textarea name="descripcion" id="descripcion" class="form-control"
                              rows="5" minlength="250" required><?= htmlspecialchars($anuncio['descripcion']); ?></textarea>
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
                                <?= $imagen['principal'] ? 'Principal' : 'Principal'; ?>
                            </button>
                            <button type="button" class="remove-image"
                                    onclick="removeImage(<?= $index; ?>)">
                                Eliminar
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Box 4: Contacto -->
            <div class="box mb-4 p-4 border rounded shadow-sm">
                <h4 class="mb-3">Información de Contacto</h4>
                <div class="form-group">
                    <label for="telefono">Teléfono *</label>
                    <input type="text" name="telefono" id="telefono" class="form-control"
                           required value="<?= htmlspecialchars($anuncio['telefono']); ?>">
                </div>

                <div class="form-group">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" 
                               id="whatsapp_switch" name="whatsapp" value="1"
                               <?= $anuncio['whatsapp'] ? 'checked' : ''; ?>>
                        <label class="custom-control-label" for="whatsapp_switch">
                                WhatsApp
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="correo">Correo Electrónico *</label>
                    <input type="email" name="correo" id="correo" class="form-control"
                           required value="<?= htmlspecialchars($anuncio['correo_electronico']); ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block mt-4">
                <i class="fas fa-save mr-2"></i>
                Guardar Cambios
            </button>
            
        </form>
    </div>

    <?php include '../includes/footer.php'; ?>

<script>
// Constantes
const MAX_FILES = 8;
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif'];

// Variables globales
let images = [];
let processedFiles = new Set();
let uploadedHashes = new Set();

// Inicializar imágenes existentes desde PHP
document.addEventListener('DOMContentLoaded', function() {
    // Las imágenes existentes se cargarán desde el PHP mediante un script inline
    updateMainImageInput();
    initializeValidations();
});

// Funciones de validación
const validations = {
    validateTitle: (title) => {
        const minLength = 40;
        const maxLength = 200;
        if (title.length < minLength) {
            return `El título debe tener al menos ${minLength} caracteres`;
        }
        if (title.length > maxLength) {
            return `El título no puede exceder los ${maxLength} caracteres`;
        }
        if (!/^[a-zA-ZáéíóúÁÉÍÓÚñÑ0-9\s.,!¡¿?-]+$/.test(title)) {
            return 'El título contiene caracteres no permitidos';
        }
        return '';
    },

    validateDescription: (description) => {
        const minLength = 250;
        const maxLength = 2000;
        if (description.length < minLength) {
            return `La descripción debe tener al menos ${minLength} caracteres`;
        }
        if (description.length > maxLength) {
            return `La descripción no puede exceder los ${maxLength} caracteres`;
        }
        return '';
    },

    validateAge: (age) => {
        const ageNum = parseInt(age);
        if (isNaN(ageNum) || ageNum < 18 || ageNum > 99) {
            return 'La edad debe estar entre 18 y 99 años';
        }
        if (!Number.isInteger(ageNum)) {
            return 'La edad debe ser un número entero';
        }
        return '';
    },

    validatePhone: (phone) => {
        const phonePattern = /^\+56 (9 \d{4} \d{4}|2 \d{3} \d{4})$/;
        if (!phonePattern.test(phone)) {
            return 'Ingresa un número de teléfono chileno válido';
        }
        return '';
    },

    validateEmail: (email) => {
        const emailPattern = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/;
        if (!emailPattern.test(email)) {
            return 'Ingresa un correo electrónico válido';
        }
        return '';
    }
};

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

async function calculateFileHash(file) {
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

function showValidationFeedback(element, error) {
    const feedbackDiv = element.nextElementSibling;
    if (error) {
        element.classList.add('is-invalid');
        element.classList.remove('is-valid');
        if (feedbackDiv && feedbackDiv.classList.contains('invalid-feedback')) {
            feedbackDiv.textContent = error;
        } else {
            const div = document.createElement('div');
            div.className = 'invalid-feedback';
            div.textContent = error;
            element.parentNode.insertBefore(div, element.nextSibling);
        }
    } else {
        element.classList.remove('is-invalid');
        element.classList.add('is-valid');
        if (feedbackDiv) {
            feedbackDiv.textContent = '';
        }
    }
}

function updateCharacterCount(element, min, max) {
    let counterDiv = element.parentNode.querySelector('.character-count');
    if (!counterDiv) {
        counterDiv = document.createElement('small');
        counterDiv.className = 'character-count text-muted';
        element.parentNode.appendChild(counterDiv);
    }
    const current = element.value.length;
    counterDiv.textContent = `${current}/${max} caracteres (mínimo ${min})`;
    counterDiv.className = `character-count text-${current < min ? 'danger' : 'muted'}`;
}

// Manejo de imágenes
async function handleFiles(files) {
    if (images.length + files.length > MAX_FILES) {
        alert(`Solo puedes subir un máximo de ${MAX_FILES} imágenes.`);
        return;
    }

    for (const file of Array.from(files)) {
        const fileId = `${file.name}-${file.size}-${file.lastModified}`;
        
        if (processedFiles.has(fileId)) {
            continue;
        }

        if (file.size > MAX_FILE_SIZE) {
            alert(`El archivo ${file.name} excede el tamaño máximo permitido (5MB)`);
            continue;
        }

        if (!ALLOWED_TYPES.includes(file.type)) {
            alert(`El archivo ${file.name} no es un tipo de imagen permitido`);
            continue;
        }

        const hash = await calculateFileHash(file);
        if (uploadedHashes.has(hash)) {
            alert('Esta imagen ya ha sido subida anteriormente.');
            continue;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            const imgObject = {
                src: e.target.result,
                file: file,
                isMain: images.length === 0,
                fileId: fileId,
                processed: false,
                hash: hash
            };
            images.push(imgObject);
            processedFiles.add(fileId);
            uploadedHashes.add(hash);
            renderImages();
        };
        reader.readAsDataURL(file);
    }
}

function setMainImage(index) {
    images.forEach((img, i) => {
        img.isMain = (i === index);
    });
    renderImages();
    updateMainImageInput();
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
    }
}

function renderImages() {
    const previewContainer = document.getElementById('image-preview');
    previewContainer.innerHTML = '';
    
    images.forEach((image, index) => {
        const imageItem = document.createElement('div');
        imageItem.classList.add('image-item');
        if (image.id) {
            imageItem.dataset.id = image.id;
        }

        const img = document.createElement('img');
        img.src = image.src;
        img.alt = 'Imagen del anuncio';
        imageItem.appendChild(img);

        const markMainButton = document.createElement('button');
        markMainButton.type = 'button';
        markMainButton.textContent = image.isMain ? 'Principal' : 'Marcar como Principal';
        markMainButton.classList.add('mark-main');
        if (image.isMain) {
            markMainButton.classList.add('active');
        }
        markMainButton.onclick = () => setMainImage(index);
        imageItem.appendChild(markMainButton);

        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.textContent = 'Eliminar';
        removeButton.classList.add('remove-image');
        removeButton.onclick = () => removeImage(index);
        imageItem.appendChild(removeButton);

        previewContainer.appendChild(imageItem);
    });
}

function updateMainImageInput() {
    const mainImage = images.find(img => img.isMain);
    document.getElementById('imagen_principal').value = mainImage?.id || '';
}

// Inicialización y eventos
function initializeValidations() {
    const titleInput = document.getElementById('titulo');
    const descriptionInput = document.getElementById('descripcion');
    const ageInput = document.getElementById('edad');
    const phoneInput = document.getElementById('telefono');
    const emailInput = document.getElementById('correo');
    const dropArea = document.getElementById('drop-area');
    const fileInput = document.getElementById('imagenes');

    titleInput.addEventListener('input', function() {
        const error = validations.validateTitle(this.value);
        showValidationFeedback(this, error);
        updateCharacterCount(this, 40, 200);
    });

    descriptionInput.addEventListener('input', function() {
        const error = validations.validateDescription(this.value);
        showValidationFeedback(this, error);
        updateCharacterCount(this, 250, 2000);
    });

    ageInput.addEventListener('input', function() {
        const error = validations.validateAge(this.value);
        showValidationFeedback(this, error);
    });

    phoneInput.addEventListener('input', function() {
        const rawValue = this.value.replace(/\D/g, '');
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

        this.value = formattedValue;
        const error = validations.validatePhone(formattedValue);
        showValidationFeedback(this, error);
    });

    emailInput.addEventListener('input', function() {
        const error = validations.validateEmail(this.value);
        showValidationFeedback(this, error);
    });

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
}

// Función para filtrar comunas
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
    formData.delete('imagenes[]');

    let hasNewImages = false;
    for (const image of images) {
        if (image.file && !image.processed) {
            formData.append('imagenes[]', image.file);
            image.processed = true;
            hasNewImages = true;
        }
    }

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
</script>
</body>
</html>     
