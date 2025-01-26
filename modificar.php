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
            images.splice(index, 1);
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
            document.getElementById('imagen_principal').value = images[mainImageIndex].id;
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