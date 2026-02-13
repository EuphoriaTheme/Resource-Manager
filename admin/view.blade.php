{{-- Blueprint admin view for the addon.
`{name}`, `{author}`, and `{identifier}` are placeholders populated by Blueprint from `conf.yml`. --}}
<div id="rm-extension">
    <div class="row">
        <div class="col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title"><strong>{name}</strong> by <strong>{author}</strong></h3>
                </div>
                <div class="box-body">
                    Identifier: <code>{identifier}</code><br>
                    Uninstall using: <code>blueprint -remove {identifier}</code><br>
                    Get support via <a href="https://discord.gg/Cus2zP4pPH" target="_blank"
                        rel="noopener noreferrer">Discord</a><br>
                    Files are served publicly from: <code>/extensions/{identifier}/uploads/</code>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-upload"></i> Upload Images</h3>
                </div>
                <div class="box-body">
                    <form id="rm-upload-form" class="form-inline" autocomplete="off">
                        <div class="form-group" style="margin-right: 10px;">
                            <label class="sr-only" for="rm-image">Select Image</label>
                            <input type="file" id="rm-image" name="image" class="form-control"
                                accept="image/svg+xml,image/bmp,image/x-icon,image/vnd.microsoft.icon,image/tiff,image/heic,image/heif,image/png,image/jpeg,image/webp,image/gif,image/avif"
                                required>
                        </div>
                        <button id="rm-upload-btn" type="submit" class="btn btn-primary">Upload</button>
                        <span id="rm-upload-hint" class="text-muted" style="margin-left: 10px;">Max 20MB. Supported:
                            SVG, JPG, PNG, WebP, GIF, BMP. Additional formats (AVIF, ICO, TIFF, HEIF/HEIC) are only accepted when enabled by the server's Imagick codecs.</span>
                    </form>

                    <hr>

                    <div class="row" style="margin-bottom: 10px;">
                        <div class="col-xs-12 col-sm-6">
                            <label class="sr-only" for="rm-search">Search</label>
                            <input id="rm-search" type="text" class="form-control" placeholder="Filter by filename..."
                                style="width: 100%;">
                        </div>
                        <div class="col-xs-12 col-sm-6" style="text-align: right; margin-top: 10px;">
                            <button id="rm-refresh" type="button" class="btn btn-default">Refresh</button>
                        </div>
                    </div>

                    <p id="rm-loading" class="text-muted" style="display: none;">Loading uploads...</p>
                    <p id="rm-empty" class="text-muted" style="display: none;">No uploads found.</p>

                    <ul id="rm-image-list" class="rm-image-list"></ul>
                </div>
            </div>
        </div>
    </div>

    <div id="rm-delete-modal" class="rm-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="rm-modal-content" role="document">
            <h4 style="margin-top: 0;">Confirm Deletion</h4>
            <p>Delete <code id="rm-delete-filename"></code>?</p>
            <div class="rm-modal-actions">
                <button id="rm-confirm-delete" type="button" class="btn btn-danger">Delete</button>
                <button id="rm-cancel-delete" type="button" class="btn btn-default">Cancel</button>
            </div>
        </div>
    </div>
</div>

<style>
    #rm-extension .rm-image-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    #rm-extension .rm-image-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 6px;
        margin-bottom: 10px;
        background: rgba(0, 0, 0, 0.06);
    }

    #rm-extension .rm-thumb {
        width: 92px;
        height: 52px;
        object-fit: cover;
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        background: rgba(0, 0, 0, 0.08);
    }

    #rm-extension .rm-meta {
        flex: 1;
        min-width: 0;
    }

    #rm-extension .rm-name {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    #rm-extension .rm-sub {
        color: #999;
        font-size: 12px;
        margin-top: 3px;
    }

    #rm-extension .rm-actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    #rm-extension .rm-modal {
        display: none;
        position: fixed;
        inset: 0;
        background-color: rgba(0, 0, 0, 0.55);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }

    #rm-extension .rm-modal-content {
        background: #0b0b0b;
        padding: 16px;
        border-radius: 8px;
        width: 340px;
        max-width: 90%;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35);
        border: 1px solid rgba(255, 255, 255, 0.08);
    }

    #rm-extension .rm-modal-actions {
        margin-top: 12px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

    #rm-extension .rm-toast-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
    }

    #rm-extension .rm-toast {
        display: flex;
        align-items: center;
        background-color: #333;
        color: #fff;
        padding: 10px 14px;
        margin-bottom: 10px;
        border-radius: 6px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        opacity: 0;
        transform: translateY(-12px);
        transition: opacity 0.2s ease, transform 0.2s ease;
    }

    #rm-extension .rm-toast.show {
        opacity: 1;
        transform: translateY(0);
    }

    #rm-extension .rm-toast.success {
        background-color: #28a745;
    }

    #rm-extension .rm-toast.error {
        background-color: #dc3545;
    }
</style>

<script>
    (() => {
        const root = document.getElementById('rm-extension');
        if (!root) return;

        const uploadForm = root.querySelector('#rm-upload-form');
        const uploadBtn = root.querySelector('#rm-upload-btn');
        const fileInput = root.querySelector('#rm-image');

        const searchInput = root.querySelector('#rm-search');
        const refreshBtn = root.querySelector('#rm-refresh');

        const loadingEl = root.querySelector('#rm-loading');
        const emptyEl = root.querySelector('#rm-empty');
        const listEl = root.querySelector('#rm-image-list');

        const deleteModal = root.querySelector('#rm-delete-modal');
        const deleteFilenameEl = root.querySelector('#rm-delete-filename');
        const confirmDeleteBtn = root.querySelector('#rm-confirm-delete');
        const cancelDeleteBtn = root.querySelector('#rm-cancel-delete');

        const listUrl = '{{ route("blueprint.extensions.resourcemanager.listImages") }}';
        const uploadUrl = '{{ route("blueprint.extensions.resourcemanager.uploadImage") }}';
        const deleteUrl = '{{ route("blueprint.extensions.resourcemanager.deleteImage") }}';
        const csrfToken = '{{ csrf_token() }}';

        let allFiles = [];
        let activeFilter = '';
        let pendingDelete = null;
        const ensureToastContainer = () => {
            let container = root.querySelector('.rm-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'rm-toast-container';
                root.appendChild(container);
            }
            return container;
        };

        const showToast = (message, type = 'success') => {
            const container = ensureToastContainer();
            const toast = document.createElement('div');
            toast.className = `rm-toast ${type}`;
            toast.textContent = message;
            container.appendChild(toast);

            requestAnimationFrame(() => toast.classList.add('show'));

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 250);
            }, 3000);
        };

        const formatBytes = (bytes) => {
            if (typeof bytes !== 'number' || !Number.isFinite(bytes)) return '';
            const units = ['B', 'KB', 'MB', 'GB'];
            let value = bytes;
            let i = 0;
            while (value >= 1024 && i < units.length - 1) {
                value /= 1024;
                i++;
            }
            const digits = i === 0 ? 0 : 1;
            return `${value.toFixed(digits)} ${units[i]}`;
        };

        const setLoading = (loading) => {
            loadingEl.style.display = loading ? 'block' : 'none';
            refreshBtn.disabled = loading;
        };

        const setUploading = (uploading) => {
            uploadBtn.disabled = uploading;
            uploadBtn.textContent = uploading ? 'Uploading...' : 'Upload';
            fileInput.disabled = uploading;
        };

        const renderList = () => {
            listEl.innerHTML = '';

            const files = allFiles.filter((f) => {
                const name = (f?.name || '').toString().toLowerCase();
                return !activeFilter || name.includes(activeFilter);
            });

            emptyEl.style.display = files.length === 0 ? 'block' : 'none';

            const frag = document.createDocumentFragment();

            files.forEach((file) => {
                const li = document.createElement('li');
                li.className = 'rm-image-item';

                const img = document.createElement('img');
                img.className = 'rm-thumb';
                img.src = file.url;
                img.alt = file.name;
                img.loading = 'lazy';

                const meta = document.createElement('div');
                meta.className = 'rm-meta';

                const name = document.createElement('div');
                name.className = 'rm-name';
                name.textContent = file.name;

                const sub = document.createElement('div');
                sub.className = 'rm-sub';
                const sizeLabel = file.size ? formatBytes(file.size) : '';
                sub.textContent = sizeLabel ? sizeLabel : '';

                meta.appendChild(name);
                meta.appendChild(sub);

                const actions = document.createElement('div');
                actions.className = 'rm-actions';

                const openBtn = document.createElement('a');
                openBtn.href = file.url;
                openBtn.target = '_blank';
                openBtn.rel = 'noopener noreferrer';
                openBtn.className = 'btn btn-default btn-sm';
                openBtn.textContent = 'Open';

                const copyBtn = document.createElement('button');
                copyBtn.type = 'button';
                copyBtn.className = 'btn btn-info btn-sm';
                copyBtn.textContent = 'Copy Link';
                copyBtn.addEventListener('click', async () => {
                    try {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            await navigator.clipboard.writeText(file.url);
                            showToast('Link copied to clipboard!', 'success');
                        } else {
                            window.prompt('Copy URL:', file.url);
                        }
                    } catch {
                        window.prompt('Copy URL:', file.url);
                    }
                });

                const delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'btn btn-danger btn-sm';
                delBtn.textContent = 'Delete';
                delBtn.addEventListener('click', () => openDeleteModal(file));

                actions.appendChild(openBtn);
                actions.appendChild(copyBtn);
                actions.appendChild(delBtn);

                li.appendChild(img);
                li.appendChild(meta);
                li.appendChild(actions);
                frag.appendChild(li);
            });

            listEl.appendChild(frag);
        };

        const openDeleteModal = (file) => {
            pendingDelete = file;
            deleteFilenameEl.textContent = file?.name || '';
            deleteModal.style.display = 'flex';
            deleteModal.setAttribute('aria-hidden', 'false');
        };

        const closeDeleteModal = () => {
            pendingDelete = null;
            deleteModal.style.display = 'none';
            deleteModal.setAttribute('aria-hidden', 'true');
        };

        const fetchImages = async () => {
            setLoading(true);
            try {
                const resp = await fetch(listUrl, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                });

                const data = await resp.json().catch(() => ({}));
                if (!resp.ok || !data.success) {
                    throw new Error(data.message || `Failed to load uploads (HTTP ${resp.status}).`);
                }

                allFiles = Array.isArray(data.files) ? data.files : [];
                renderList();
            } catch (e) {
                console.error(e);
                showToast(e?.message || 'Failed to load uploads.', 'error');
            } finally {
                setLoading(false);
            }
        };

        uploadForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (!fileInput.files || fileInput.files.length === 0) {
                showToast('Select an image first.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('image', fileInput.files[0]);

            setUploading(true);
            try {
                const resp = await fetch(uploadUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    body: formData,
                });

                const data = await resp.json().catch(() => ({}));
                if (!resp.ok || !data.success) {
                    throw new Error(data.message || `Upload failed (HTTP ${resp.status}).`);
                }

                fileInput.value = '';
                showToast(data.message || 'Uploaded successfully.', 'success');
                await fetchImages();
            } catch (e) {
                console.error(e);
                showToast(e?.message || 'Upload failed.', 'error');
            } finally {
                setUploading(false);
            }
        });

        confirmDeleteBtn.addEventListener('click', async () => {
            if (!pendingDelete) return;
            confirmDeleteBtn.disabled = true;

            try {
                const resp = await fetch(deleteUrl, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ filename: pendingDelete.name }),
                });

                const data = await resp.json().catch(() => ({}));
                if (!resp.ok || !data.success) {
                    throw new Error(data.message || `Delete failed (HTTP ${resp.status}).`);
                }

                showToast('Image deleted successfully.', 'success');
                closeDeleteModal();
                await fetchImages();
            } catch (e) {
                console.error(e);
                showToast(e?.message || 'Failed to delete image.', 'error');
            } finally {
                confirmDeleteBtn.disabled = false;
            }
        });

        cancelDeleteBtn.addEventListener('click', closeDeleteModal);

        deleteModal.addEventListener('click', (e) => {
            if (e.target === deleteModal) closeDeleteModal();
        });

        refreshBtn.addEventListener('click', fetchImages);

        searchInput.addEventListener('input', () => {
            activeFilter = searchInput.value.trim().toLowerCase();
            renderList();
        });

        fetchImages();
    })();
</script>