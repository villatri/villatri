<?php
// Conexión a la base de datos
include '../includes/config.php';
include 'functions.php';

// Iniciar sesión
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

// consulta para obtener el email del usuario
$queryUsuario = "SELECT email FROM usuarios WHERE id = ?";
$stmtUsuario = $conn->prepare($queryUsuario);
$stmtUsuario->bind_param('i', $usuario_id);
$stmtUsuario->execute();
$resultUsuario = $stmtUsuario->get_result();
$usuario = $resultUsuario->fetch_assoc();
$emailUsuario = $usuario['email'];

// Manejar solicitudes AJAX para filtrar comunas
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

// Obtener las ciudades desde la base de datos
$queryCiudades = "SELECT id, nombre FROM ciudades ORDER BY nombre";
$resultCiudades = $conn->query($queryCiudades);

// Obtener las categorías desde la base de datos
$queryCategorias = "SELECT id, nombre FROM categorias";
$resultCategorias = $conn->query($queryCategorias);

// Procesamiento del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitización de datos
    $categoria_id = intval($_POST['categoria_id']);
    $comuna_id = intval($_POST['comuna_id']);
    $ciudad_id = intval($_POST['ciudad_id']);
    $edad = intval($_POST['edad']);
    $titulo = htmlspecialchars(trim($_POST['titulo']));
    $descripcion = htmlspecialchars(trim($_POST['descripcion']));
    $telefono = htmlspecialchars(trim($_POST['telefono']));
    $whatsapp = isset($_POST['whatsapp']) ? 1 : 0;
    $correo = filter_var(trim($_POST['correo']), FILTER_SANITIZE_EMAIL);

    // Validación básica
    if (empty($titulo) || empty($descripcion) || empty($telefono) || empty($correo)) {
        die("Todos los campos son obligatorios");
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        die("Correo electrónico inválido");
    }

    // Manejo de imágenes
    $imagenes = $_FILES['imagenes'];
    $rutaImagenes = '../uploads/';
    $imagenesGuardadas = [];
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

    if (!is_dir($rutaImagenes)) {
        mkdir($rutaImagenes, 0777, true);
    }

    foreach ($imagenes['tmp_name'] as $index => $tmpName) {
        if ($tmpName) {
            // Validaciones de imagen
            if ($imagenes['size'][$index] > $maxFileSize) {
                die("El archivo es demasiado grande. Máximo 5MB permitido.");
            }

            if (!in_array($imagenes['type'][$index], $allowedTypes)) {
                die("Tipo de archivo no permitido. Solo se permiten JPG, PNG y GIF.");
            }

            if (count($imagenesGuardadas) >= 8) {
                break;
            }

            $nombreArchivo = uniqid() . '_' . basename($imagenes['name'][$index]);
            $rutaDestino = $rutaImagenes . $nombreArchivo;

            if (move_uploaded_file($tmpName, $rutaDestino)) {
                // Agregar la marca de agua
                $textoMarca = "INFOESCORT.CL";
                addTextWatermark($rutaDestino, $textoMarca, $rutaDestino);

                $imagenesGuardadas[] = [
                    'ruta' => $rutaDestino,
                    'principal' => count($imagenesGuardadas) === 0 ? 1 : 0,
                ];
            }
        }
    }

    // Iniciar transacción
    $conn->begin_transaction();

    try {
        // Guardar datos en la base de datos
        $query = "INSERT INTO anuncios (categoria_id, comuna_id, ciudad_id, edad, titulo, descripcion, 
                  telefono, whatsapp, correo_electronico, usuario_id) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            throw new Exception("Error en la preparación de la consulta: " . $conn->error);
        }

        $stmt->bind_param('iiiisssssi', 
            $categoria_id, $comuna_id, $ciudad_id, $edad, $titulo, 
            $descripcion, $telefono, $whatsapp, $correo, $usuario_id
        );
        
        $stmt->execute();

        if ($stmt->error) {
            throw new Exception("Error en la ejecución de la consulta: " . $stmt->error);
        }

        $listingId = $stmt->insert_id;

        // Guardar imágenes
        foreach ($imagenesGuardadas as $imagen) {
            $queryImg = "INSERT INTO imagenes_anuncios (anuncio_id, url_imagen, principal) VALUES (?, ?, ?)";
            $stmtImg = $conn->prepare($queryImg);

            if (!$stmtImg) {
                throw new Exception("Error en la preparación de la consulta de imágenes: " . $conn->error);
            }

            $stmtImg->bind_param('isi', $listingId, $imagen['ruta'], $imagen['principal']);
            $stmtImg->execute();

            if ($stmtImg->error) {
                throw new Exception("Error en la ejecución de la consulta de imágenes: " . $stmtImg->error);
            }
        }

        // Confirmar transacción
        $conn->commit();

        $mensaje = 'Anuncio publicado correctamente';
        echo "<script>alert('$mensaje'); window.location.href='index.php';</script>";
        exit;

    } catch (Exception $e) {
        // Revertir transacción en caso de error
        $conn->rollback();
        die("Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publicar Anuncio</title>
    
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
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publicar Anuncio</title>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container my-5">
        <h1 class="text-center mb-4">Publicar Anuncio</h1>
        <form action="" method="POST" enctype="multipart/form-data" id="publicarForm">
            <!-- Box 1: Categoría, Ciudad y Comuna -->
            <div class="box">
                <h4>Información del Anuncio</h4>
                <div class="form-group mb-4">
                    <label for="categoria_id">Categoría</label>
                    <select name="categoria_id" id="categoria_id" class="form-control" required>
                        <option value="" disabled selected>Selecciona una categoría</option>
                        <?php while ($categoria = $resultCategorias->fetch_assoc()): ?>
                            <option value="<?= $categoria['id']; ?>">
                                <?= htmlspecialchars($categoria['nombre']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="ciudad_id">Ciudad</label>
                            <select name="ciudad_id" id="ciudad_id" class="form-control" 
                                    onchange="filtrarComunas()" required>
                                <option value="" disabled selected>Selecciona una ciudad</option>
                                <?php while ($ciudad = $resultCiudades->fetch_assoc()): ?>
                                    <option value="<?= $ciudad['id']; ?>">
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
                                <option value="" disabled selected>Selecciona una comuna</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Box 2: Edad, Título y Descripción -->
            <div class="box mb-4 p-4 border rounded shadow-sm">
                <h4>Detalles del Anuncio</h4>
                <div class="form-row">
                    <div class="mb-4">
                        <div class="form-group">
                            <label for="edad">Edad</label>
                            <input type="number" name="edad" id="edad" class="form-control"
                                   min="18" max="99" required placeholder="18+">
                        </div>
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
                           placeholder="Escribe un título atractivo para tu anuncio">
                    <div class="invalid-feedback">El título debe tener entre 40 y 70 caracteres</div>
                    <div class="form-text text-end" id="tituloCaracteres">0 caracteres (mínimo 40 - maximo 70)</div>
                </div>

                <!-- Descripción -->
                <div class="form-group mb-4">
                    <label for="descripcion" class="form-label d-flex justify-content-between align-items-center">
                        <span>Descripción *</span>
                    </label>
                    <textarea name="descripcion" 
                              id="descripcion" 
                              class="form-control"
                              rows="5"
                              minlength="150"
                              maxlength="1000"
                              required
                              placeholder="Describe detalladamente tu servicio..."></textarea>
                    <div class="invalid-feedback">La descripción debe tener entre 150 y 1000 caracteres</div>
                    <div class="form-text text-end" id="descripcionCaracteres">0 caracteres (mínimo 150 - maximo 1000)</div>
                </div>
            </div>

            <!-- Box 3: Imágenes -->
            <div class="box mb-4 p-4 border rounded shadow-sm">
                <h4 class="mb-3">Imágenes del Anuncio</h4>
                <div class="image-upload-container" id="drop-area">
                    <i class="fas fa-cloud-upload-alt fa-3x mb-3"></i>
                    <p class="mb-0">Arrastra y suelta imágenes aquí o haz clic para seleccionar</p>
                    <small class="text-muted">Máximo 8 imágenes, 5MB por imagen</small>
                    <input type="file" name="imagenes[]" id="imagenes" class="d-none"
                           multiple accept=".jpg,.jpeg,.png,.gif">
                </div>
                <div class="image-grid" id="image-preview"></div>
            </div>

            <!-- Box 4: Contacto -->
            <div class="box">
                <h4>Información de Contacto</h4>
                <div class="form-group mb-4">
                    <label for="telefono">Teléfono</label>
                    <input type="text" name="telefono" id="telefono" class="form-control"
                           required placeholder="+56 9 XXXX XXXX">
                </div>

                <div class="form-group mb-4">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" 
                               id="whatsapp_switch" name="whatsapp" value="1">
                        <label class="custom-control-label" for="whatsapp_switch">
                            <i class="fab fa-whatsapp"></i> Activar WhatsApp
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

    function showLoading() {
        const loadingDiv = document.createElement('div');
        loadingDiv.id = 'loading';
        loadingDiv.innerHTML = '<div class="spinner-border text-primary" role="status">' +
                              '<span class="sr-only">Cargando...</span></div>';
        document.body.appendChild(loadingDiv);
    }

    function hideLoading() {
        const loadingDiv = document.getElementById('loading');
        if (loadingDiv) {
            loadingDiv.remove();
        }
    }

function filtrarComunas() {
    const ciudadId = document.getElementById('ciudad_id').value;
    const comunaSelect = document.getElementById('comuna_id');
    
    // Deshabilitar el select de comuna mientras carga
    comunaSelect.disabled = true;
    
    // Mostrar estado de carga
    comunaSelect.innerHTML = '<option value="" disabled selected>Cargando comunas...</option>';

    fetch(`publicar.php?ciudad_id=${ciudadId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la red');
            }
            return response.json();
        })
        .then(comunas => {
            // Limpiar y agregar nueva opción por defecto
            comunaSelect.innerHTML = '<option value="" disabled selected>Selecciona una comuna</option>';
            
            // Agregar las comunas ordenadas alfabéticamente
            comunas.sort((a, b) => a.nombre.localeCompare(b.nombre))
                  .forEach(comuna => {
                const option = document.createElement('option');
                option.value = comuna.id;
                option.textContent = comuna.nombre;
                comunaSelect.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error:', error);
            comunaSelect.innerHTML = '<option value="" disabled selected>Error al cargar comunas</option>';
        })
        .finally(() => {
            // Reactivar el select de comuna
            comunaSelect.disabled = false;
        });
}

// Validación de selects
document.querySelectorAll('select').forEach(select => {
    select.addEventListener('change', function() {
        if (this.value) {
            this.classList.add('is-valid');
            this.classList.remove('is-invalid');
        } else {
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
        }
    });
});

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

    const dropArea = document.getElementById('drop-area');
    const fileInput = document.getElementById('imagenes');
    const previewContainer = document.getElementById('image-preview');

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
        const files = Array.from(e.dataTransfer.files);
        handleFiles(files);
    });

    fileInput.addEventListener('change', (e) => {
        const files = Array.from(e.target.files);
        handleFiles(files);
    });

    function handleFiles(files) {
        if (images.length + files.length > MAX_FILES) {
            alert(`Solo puedes subir un máximo de ${MAX_FILES} imágenes.`);
            return;
        }

        files.forEach(file => {
            if (validateFileSize(file) && validateFileType(file)) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    const imgObject = {
                        src: e.target.result,
                        file: file,
                        isMain: images.length === 0
                    };
                    images.push(imgObject);
                    renderImages();
                };
                reader.readAsDataURL(file);
            }
        });
    }

function setMainImage(index) {
    images.forEach((img, i) => img.isMain = (i === index));
    renderImages();
}

function removeImage(index) {
    if (confirm('¿Estás seguro de que deseas eliminar esta imagen?')) {
        const removedImage = images.splice(index, 1)[0];
        processedFiles.delete(removedImage.fileId);
        uploadedHashes.delete(removedImage.hash);
        
        if (images.length > 0 && !images.some(img => img.isMain)) {
            images[0].isMain = true;
        }
        renderImages();
    }
}

function renderImages() {
    const previewContainer = document.getElementById('image-preview');
    previewContainer.innerHTML = '';
    
    images.forEach((image, index) => {
        const imageItem = document.createElement('div');
        imageItem.classList.add('image-item');
        if (image.isMain) {
            imageItem.setAttribute('data-main', 'true');
        }

        const img = document.createElement('img');
        img.src = image.src;
        img.alt = 'Imagen del anuncio';
        imageItem.appendChild(img);

        // Botón Principal con icono
        const markMainButton = document.createElement('button');
        markMainButton.type = 'button';
        markMainButton.classList.add('mark-main');
        if (image.isMain) markMainButton.classList.add('active');
        
        const starIcon = document.createElement('i');
        starIcon.classList.add(image.isMain ? 'fas' : 'far', 'fa-star');
        markMainButton.appendChild(starIcon);
        
        markMainButton.onclick = () => setMainImage(index);
        imageItem.appendChild(markMainButton);

        // Botón Eliminar con icono
        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.classList.add('remove-image');
        
        const trashIcon = document.createElement('i');
        trashIcon.classList.add('fas', 'fa-trash-alt');
        removeButton.appendChild(trashIcon);
        
        removeButton.onclick = () => removeImage(index);
        imageItem.appendChild(removeButton);

        previewContainer.appendChild(imageItem);
    });
}
    document.getElementById('publicarForm').addEventListener('submit', function(e) {
        e.preventDefault();

        if (images.length === 0) {
            alert('Debes subir al menos una imagen');
            return;
        }

        showLoading();

        const formData = new FormData(this);

        fetch(this.action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la red');
            }
            return response.text();
        })
        .then(data => {
            if (data.includes('error')) {
                throw new Error(data);
            }
            alert('Anuncio publicado correctamente');
            window.location.href = 'index.php';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al publicar el anuncio. Por favor, intente nuevamente.');
        })
        .finally(() => {
            hideLoading();
        });
    });

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
    
// Función para actualizar contadores
function actualizarContador(elemento, minimo, maximo) {
    const contador = document.getElementById(`${elemento.id}Contador`);
    const caracteresActuales = elemento.value.length;
    
    contador.textContent = `${caracteresActuales}/${maximo} caracteres (mínimo ${minimo})`;
    contador.classList.add('text-success'); // Siempre verde
    
    // Validación del campo
    if (caracteresActuales === 0) {
        elemento.classList.remove('is-valid', 'is-invalid');
    } else if (caracteresActuales >= minimo && caracteresActuales <= maximo) {
        elemento.classList.add('is-valid');
        elemento.classList.remove('is-invalid');
    } else {
        elemento.classList.add('is-invalid');
        elemento.classList.remove('is-valid');
    }
}

// Validación de edad
document.getElementById('edad').addEventListener('input', function() {
    const edad = parseInt(this.value);
    const esValido = !isNaN(edad) && edad >= 18 && edad <= 99;
    
    this.classList.toggle('is-valid', esValido);
    this.classList.toggle('is-invalid', !esValido);
});

// Contador de caracteres para título
document.getElementById('titulo').addEventListener('input', function() {
    const caracteresActuales = this.value.length;
    const contador = document.getElementById('tituloCaracteres');
    contador.textContent = `${caracteresActuales} caracteres (mínimo 40 - maximo 70)`;
    
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
    contador.textContent = `${caracteresActuales} caracteres (mínimo 150 - maximo 1000)`;
    
    if (caracteresActuales < 150) {
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

    
</script>
</body>
</html>
