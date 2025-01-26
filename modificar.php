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

// Obtener el ID del anuncio a modificar
if (!isset($_GET['id'])) {
    die("ID de anuncio no proporcionado");
}
$anuncio_id = intval($_GET['id']);

// Obtener los datos del anuncio
$queryAnuncio = "SELECT * FROM anuncios WHERE id = ? AND usuario_id = ?";
$stmtAnuncio = $conn->prepare($queryAnuncio);
$stmtAnuncio->bind_param('ii', $anuncio_id, $usuario_id);
$stmtAnuncio->execute();
$resultAnuncio = $stmtAnuncio->get_result();

if ($resultAnuncio->num_rows === 0) {
    die("Anuncio no encontrado o no tienes permiso para editarlo");
}

$anuncio = $resultAnuncio->fetch_assoc();

// Obtener las imágenes del anuncio
$queryImagenes = "SELECT * FROM imagenes_anuncios WHERE anuncio_id = ?";
$stmtImagenes = $conn->prepare($queryImagenes);
$stmtImagenes->bind_param('i', $anuncio_id);
$stmtImagenes->execute();
$resultImagenes = $stmtImagenes->get_result();
$imagenesAnuncio = $resultImagenes->fetch_all(MYSQLI_ASSOC);

// Obtener las ciudades y categorías
$queryCiudades = "SELECT id, nombre FROM ciudades ORDER BY nombre";
$resultCiudades = $conn->query($queryCiudades);

$queryCategorias = "SELECT id, nombre FROM categorias";
$resultCategorias = $conn->query($queryCategorias);

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

// Procesamiento del formulario de modificación
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

    // Obtener el ID de la imagen principal
    $imagen_principal_id = intval($_POST['imagen_principal']);

    // Manejo de imágenes
    $imagenes = $_FILES['imagenes'];
    $rutaImagenes = '../uploads/';
    $imagenesGuardadas = [];
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

    if (!is_dir($rutaImagenes)) {
        mkdir($rutaImagenes, 0777, true);
    }

    // Procesar nuevas imágenes
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
        // Actualizar datos del anuncio
        $query = "UPDATE anuncios SET 
                  categoria_id = ?, comuna_id = ?, ciudad_id = ?, edad = ?, titulo = ?, 
                  descripcion = ?, telefono = ?, whatsapp = ?, correo_electronico = ? 
                  WHERE id = ? AND usuario_id = ?";
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            throw new Exception("Error en la preparación de la consulta: " . $conn->error);
        }

        $stmt->bind_param('iiiisssssii', 
            $categoria_id, $comuna_id, $ciudad_id, $edad, $titulo, 
            $descripcion, $telefono, $whatsapp, $correo, $anuncio_id, $usuario_id
        );
        
        $stmt->execute();

        if ($stmt->error) {
            throw new Exception("Error en la ejecución de la consulta: " . $stmt->error);
        }

        // Eliminar imágenes eliminadas por el usuario
        $imagenesEliminadas = $_POST['imagenes_eliminadas'] ?? [];
        foreach ($imagenesEliminadas as $imagenId) {
            $queryDeleteImage = "DELETE FROM imagenes_anuncios WHERE id = ? AND anuncio_id = ?";
            $stmtDeleteImage = $conn->prepare($queryDeleteImage);
            $stmtDeleteImage->bind_param('ii', $imagenId, $anuncio_id);
            $stmtDeleteImage->execute();

            // Eliminar el archivo de imagen del servidor
            $querySelectImage = "SELECT url_imagen FROM imagenes_anuncios WHERE id = ?";
            $stmtSelectImage = $conn->prepare($querySelectImage);
            $stmtSelectImage->bind_param('i', $imagenId);
            $stmtSelectImage->execute();
            $resultSelectImage = $stmtSelectImage->get_result();
            $imagePath = $resultSelectImage->fetch_assoc()['url_imagen'];
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }

        // Guardar nuevas imágenes
        foreach ($imagenesGuardadas as $imagen) {
            $queryImg = "INSERT INTO imagenes_anuncios (anuncio_id, url_imagen, principal) VALUES (?, ?, ?)";
            $stmtImg = $conn->prepare($queryImg);

            if (!$stmtImg) {
                throw new Exception("Error en la preparación de la consulta de imágenes: " . $conn->error);
            }

            $stmtImg->bind_param('isi', $anuncio_id, $imagen['ruta'], $imagen['principal']);
            $stmtImg->execute();

            if ($stmtImg->error) {
                throw new Exception("Error en la ejecución de la consulta de imágenes: " . $stmtImg->error);
            }
        }

        // Confirmar transacción
        $conn->commit();

        $mensaje = 'Anuncio actualizado correctamente';
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
    <title>Modificar Anuncio</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .image-upload-container {
            border: 2px dashed #ccc;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            margin-bottom: 20px;
        }

        .image-upload-container.dragover {
            border-color: #007bff;
        }

        .image-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }

        .image-item {
            position: relative;
            width: 100%;
            padding-top: 100%;
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }

        .image-item img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-item .mark-main,
        .image-item .remove-image {
            position: absolute;
            bottom: 5px;
            background: rgba(0, 0, 0, 0.6);
            color: #fff;
            border: none;
            border-radius: 3px;
            padding: 5px 10px;
            font-size: 12px;
            cursor: pointer;
        }

        .image-item .mark-main {
            left: 5px;
        }

        .image-item .remove-image {
            right: 5px;
        }

        .image-item .mark-main.active {
            background: #007bff;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container my-5">
        <h1 class="text-center mb-4">Modificar Anuncio</h1>
        <form action="" method="POST" enctype="multipart/form-data" id="modificarForm">
            <!-- Campo oculto para la imagen principal -->
            <input type="hidden" name="imagen_principal" id="imagen_principal" value="<?= $anuncio['imagen_principal']; ?>">
            <!-- Campo oculto para las imágenes eliminadas -->
            <input type="hidden" name="imagenes_eliminadas" id="imagenes_eliminadas" value="[]">

            <!-- Box 1: Categoría, Ciudad y Comuna -->
            <div class="box mb-4 p-3 border rounded">
                <h4 class="mb-3">Anuncio</h4>
                <div class="form-group">
                    <label for="categoria_id">Categoría</label>
                    <select name="categoria_id" id="categoria_id" class="form-control" required>
                        <option value="" disabled>Selecciona una categoría</option>
                        <?php while ($categoria = $resultCategorias->fetch_assoc()): ?>
                            <option value="<?= $categoria['id']; ?>" <?= $categoria['id'] == $anuncio['categoria_id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($categoria['nombre']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="ciudad_id">Ciudad</label>
                    <select name="ciudad_id" id="ciudad_id" class="form-control" onchange="filtrarComunas()" required>
                        <option value="" disabled>Selecciona una ciudad</option>
                        <?php while ($ciudad = $resultCiudades->fetch_assoc()): ?>
                            <option value="<?= $ciudad['id']; ?>" <?= $ciudad['id'] == $anuncio['ciudad_id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($ciudad['nombre']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
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
                            <option value="<?= $comuna['id']; ?>" <?= $comuna['id'] == $anuncio['comuna_id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($comuna['nombre']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <!-- Box 2: Edad, Título y Descripción -->
            <div class="box mb-4 p-3 border rounded"> 
                <h4 class="mb-3">Datos</h4>
                <div class="form-row d-flex">
                    <div class="col-md-2 col-xs-12 form-group">
                        <label>Edad</label>
                        <input name="edad" type="number" id="edad" class="form-control" 
                               placeholder="Ingresa tu edad" required min="18" max="99" 
                               value="<?= htmlspecialchars($anuncio['edad']); ?>">
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label for="titulo" class="d-flex justify-content-between">
                        <span>* Título</span>
                        <small class="text-muted">Se necesitan 40 caracteres</small>
                    </label>
                    <input type="text" name="titulo" id="titulo" class="form-control" 
                           placeholder="Ponle un buen título a tu anuncio" minlength="40" required 
                           value="<?= htmlspecialchars($anuncio['titulo']); ?>">
                </div>

                <div class="form-group mt-3">
                    <label for="descripcion" class="d-flex justify-content-between">
                        <span>* Texto</span>
                        <small class="text-muted">Se necesitan 250 caracteres</small>
                    </label>
                    <textarea name="descripcion" id="descripcion" class="form-control" rows="5" 
                              placeholder="Utiliza este espacio para decir cómo eres, describir tu cuerpo, comentar tus habilidades, indicar lo que te gusta..." 
                              minlength="250" required><?= htmlspecialchars($anuncio['descripcion']); ?></textarea>
                </div>
            </div>

            <!-- Box 3: Teléfono, WhatsApp y Correo -->
            <div class="box mb-4 p-3 border rounded">
                <h4 class="mb-3">Contacto</h4>
                <div class="form-group">
                    <label for="telefono">Teléfono</label>
                    <input type="text" name="telefono" id="telefono" class="form-control" 
                           placeholder="+56 2 21234567" required 
                           title="El número debe estar en el formato: +56 2 21234567" 
                           value="<?= htmlspecialchars($anuncio['telefono']); ?>">
                </div>

                <div class="form-group mt-3">
                    <label for="whatsapp">WhatsApp</label>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="whatsapp_switch" 
                               name="whatsapp" value="1" <?= $anuncio['whatsapp'] ? 'checked' : ''; ?>>
                        <label class="custom-control-label" for="whatsapp_switch">Activar WhatsApp</label>
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label for="correo">Correo Electrónico</label>
                    <input type="email" name="correo" id="correo" class="form-control" 
                           placeholder="Ingresa tu correo electrónico" required 
                           value="<?= htmlspecialchars($anuncio['correo_electronico']); ?>">
                </div>
            </div>

            <!-- Box 4: Subida de Imágenes -->
            <div class="box mb-4 p-3 border rounded">
                <h4 class="mb-3">Imágenes del Anuncio</h4>
                <div class="image-upload-container" id="drop-area">
                    <p>Arrastra y suelta imágenes aquí o haz clic para seleccionar</p>
                    <input type="file" name="imagenes[]" id="imagenes" class="d-none" multiple accept=".jpg,.jpeg,.png,.gif">
                </div>
                <div class="image-grid" id="image-preview">
                    <?php foreach ($imagenesAnuncio as $index => $imagen): ?>
                        <div class="image-item" id="image-item-<?= $index; ?>">
                            <img src="<?= htmlspecialchars($imagen['url_imagen']); ?>" alt="Imagen del anuncio">
                            <button type="button" class="mark-main <?= $imagen['principal'] ? 'active' : ''; ?>" 
                                    onclick="setMainImage(<?= $index; ?>)">
                                <?= $imagen['principal'] ? 'Principal' : 'Marcar como Principal'; ?>
                            </button>
                            <button type="button" class="remove-image" onclick="removeImage(<?= $index; ?>)">
                                Eliminar
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block mt-4">Guardar Cambios</button>
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

    // Agregar imágenes existentes al array `images` al cargar la página
    <?php foreach ($imagenesAnuncio as $imagen): ?>
        images.push({
            src: "<?= htmlspecialchars($imagen['url_imagen']); ?>",
            isMain: <?= $imagen['principal'] ? 'true' : 'false'; ?>,
            id: <?= $imagen['id']; ?> // Agregar el ID de la imagen si es necesario
        });
    <?php endforeach; ?>
    
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

        showLoading();
        comunaSelect.innerHTML = '<option value="" disabled selected>Cargando comunas...</option>';

        fetch(`modificar_anuncio.php?ciudad_id=${ciudadId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error en la red');
                }
                return response.json();
            })
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
            if (images.length > 0 && !images.some(img => img.isMain)) {
                images[0].isMain = true;
            }
            renderImages();
            updateMainImageInput();
        }
    }

    function renderImages() {
        previewContainer.innerHTML = '';
        images.forEach((image, index) => {
            const imageItem = document.createElement('div');
            imageItem.classList.add('image-item');

            const img = document.createElement('img');
            img.src = image.src;
            imageItem.appendChild(img);

            const markMainButton = document.createElement('button');
            markMainButton.type = 'button';
            markMainButton.textContent = image.isMain ? 'Principal' : 'Marcar como Principal';
            markMainButton.classList.add('mark-main');
            if (image.isMain) {
                markMainButton.classList.add('active');
            }
            markMainButton.addEventListener('click', () => setMainImage(index));
            imageItem.appendChild(markMainButton);

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = 'Eliminar';
            removeButton.classList.add('remove-image');
            removeButton.addEventListener('click', () => removeImage(index));
            imageItem.appendChild(removeButton);

            previewContainer.appendChild(imageItem);
        });
    }

    function updateMainImageInput() {
        const mainImageIndex = images.findIndex(img => img.isMain);
        if (mainImageIndex !== -1) {
            document.getElementById('imagen_principal').value = images[mainImageIndex].id || '';
        }
    }

    // Inicializar el campo oculto con la imagen principal actual
    updateMainImageInput();

    document.getElementById('modificarForm').addEventListener('submit', function(e) {
        e.preventDefault();

        if (images.length === 0 && document.querySelectorAll('#image-preview .image-item').length === 0) {
            alert('Debes subir al menos una imagen');
            return;
        }

        showLoading();

        const formData = new FormData(this);

        // Agregar las imágenes seleccionadas al FormData
        images.forEach((image, index) => {
            if (image.file) {
                formData.append('imagenes[]', image.file);
            }
        });

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
            alert('Anuncio actualizado correctamente');
            window.location.href = 'index.php';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al actualizar el anuncio. Por favor, intente nuevamente.');
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
</script>
</body>
</html>            
