/**
 * Green AVIC - Content Studio Admin Client
 * Manages single-publication workflow, cover upload, rich text editing, drafts, and publishing
 */

document.addEventListener('DOMContentLoaded', () => {
    // ----------------------------------------------------
    // State Variables
    // ----------------------------------------------------
    let csrfToken = sessionStorage.getItem('greenavic_csrf') || '';
    let currentPublishedData = null;
    let currentDraftData = null;
    let isEditingExisting = false;

    // ----------------------------------------------------
    // DOM Elements - Navigation & Header
    // ----------------------------------------------------
    const adminEmailDisplay = document.getElementById('adminEmailDisplay');
    const btnLogout = document.getElementById('btnLogout');
    const toastBar = document.getElementById('toastBar');
    const toastMessage = document.getElementById('toastMessage');

    // ----------------------------------------------------
    // DOM Elements - Overview Panel
    // ----------------------------------------------------
    const overviewSection = document.getElementById('overviewSection');
    const overviewBadge = document.getElementById('overviewBadge');
    const overviewStatusText = document.getElementById('overviewStatusText');
    const overviewLastUpdated = document.getElementById('overviewLastUpdated');
    const overviewPublishedDetails = document.getElementById('overviewPublishedDetails');
    const overviewEmptyDetails = document.getElementById('overviewEmptyDetails');
    const overviewThumbnail = document.getElementById('overviewThumbnail');
    const overviewThumbContainer = document.getElementById('overviewThumbContainer');
    const overviewCategory = document.getElementById('overviewCategory');
    const overviewTitle = document.getElementById('overviewTitle');
    const overviewExcerpt = document.getElementById('overviewExcerpt');
    const overviewAuthor = document.getElementById('overviewAuthor');
    const overviewDate = document.getElementById('overviewDate');
    const overviewReadTime = document.getElementById('overviewReadTime');

    const btnWriteNewPost = document.getElementById('btnWriteNewPost');
    const btnEditCurrent = document.getElementById('btnEditCurrent');
    const btnPreviewCurrent = document.getElementById('btnPreviewCurrent');
    const btnEmptyCreate = document.getElementById('btnEmptyCreate');

    const draftAlertBanner = document.getElementById('draftAlertBanner');
    const draftBannerText = document.getElementById('draftBannerText');
    const btnResumeDraft = document.getElementById('btnResumeDraft');
    const btnDiscardDraftAlert = document.getElementById('btnDiscardDraftAlert');

    // ----------------------------------------------------
    // DOM Elements - Editor Section
    // ----------------------------------------------------
    const editorSection = document.getElementById('editorSection');
    const editorModeHeading = document.getElementById('editorModeHeading');
    const editorStatusBadge = document.getElementById('editorStatusBadge');
    const btnCancelEditor = document.getElementById('btnCancelEditor');
    const btnDiscardEditor = document.getElementById('btnDiscardEditor');
    const btnSaveDraft = document.getElementById('btnSaveDraft');
    const btnPreviewEditor = document.getElementById('btnPreviewEditor');
    const btnPublishPost = document.getElementById('btnPublishPost');

    const postTitle = document.getElementById('postTitle');
    const postCategory = document.getElementById('postCategory');
    const postAuthor = document.getElementById('postAuthor');
    const postExcerpt = document.getElementById('postExcerpt');
    const postCoverImage = document.getElementById('postCoverImage');
    const postCoverAlt = document.getElementById('postCoverAlt');
    const coverAltGroup = document.getElementById('coverAltGroup');
    const postContentArea = document.getElementById('postContentArea');

    const titleCharCount = document.getElementById('titleCharCount');
    const excerptCharCount = document.getElementById('excerptCharCount');
    const bodyWordCount = document.getElementById('bodyWordCount');

    // Image Upload Elements
    const uploadDropzone = document.getElementById('uploadDropzone');
    const coverFileInput = document.getElementById('coverFileInput');
    const uploadProgressBar = document.getElementById('uploadProgressBar');
    const uploadProgressFill = document.getElementById('uploadProgressFill');
    const uploadPreviewContainer = document.getElementById('uploadPreviewContainer');
    const uploadPreviewImg = document.getElementById('uploadPreviewImg');
    const previewFileName = document.getElementById('previewFileName');
    const btnReplaceImage = document.getElementById('btnReplaceImage');
    const btnRemoveImage = document.getElementById('btnRemoveImage');

    // Modals
    const previewModal = document.getElementById('previewModal');
    const previewModalBody = document.getElementById('previewModalBody');
    const btnClosePreviewModal = document.getElementById('btnClosePreviewModal');
    const btnClosePreviewModalBottom = document.getElementById('btnClosePreviewModalBottom');

    const confirmModal = document.getElementById('confirmModal');
    const btnCancelConfirm = document.getElementById('btnCancelConfirm');
    const btnCancelConfirmTop = document.getElementById('btnCancelConfirmTop');
    const btnProceedPublish = document.getElementById('btnProceedPublish');

    // ----------------------------------------------------
    // Utilities
    // ----------------------------------------------------
    function showToast(message, isError = false) {
        if (!toastBar || !toastMessage) return;
        toastMessage.textContent = message;
        toastBar.className = 'toast-bar' + (isError ? ' toast-error' : '') + ' show';
        setTimeout(() => {
            toastBar.classList.remove('show');
        }, 3500);
    }

    function formatDate(isoString) {
        if (!isoString) return '';
        try {
            const date = new Date(isoString);
            if (isNaN(date.getTime())) return isoString;
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (e) {
            return isoString;
        }
    }

    // ----------------------------------------------------
    // Authentication Check & Logout
    // ----------------------------------------------------
    async function verifyAuth() {
        try {
            let res;
            try {
                res = await fetch('../api/auth.php?action=check', { cache: 'no-cache' });
            } catch (err) {
                res = await fetch('../api/auth', { cache: 'no-cache' });
            }

            if (!res.ok) {
                window.location.replace('/admin/login');
                return;
            }

            const data = await res.json();
            if (!data || !data.authenticated) {
                window.location.replace('/admin/login');
                return;
            }

            if (data.email) {
                adminEmailDisplay.textContent = data.email;
            }

            if (data.csrf_token) {
                csrfToken = data.csrf_token;
                sessionStorage.setItem('greenavic_csrf', csrfToken);
            }

            // Once verified, load publication data
            loadPublicationData();
        } catch (e) {
            console.error('Auth verification error:', e);
            window.location.replace('/admin/login');
        }
    }

    if (btnLogout) {
        btnLogout.addEventListener('click', async () => {
            try {
                const payload = { action: 'logout', csrf_token: csrfToken };
                try {
                    await fetch('../api/auth.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                        body: JSON.stringify(payload)
                    });
                } catch (e) {
                    await fetch('../api/auth', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                        body: JSON.stringify(payload)
                    });
                }
            } catch (e) {
                // Ignore logout network error and redirect
            }
            sessionStorage.removeItem('greenavic_csrf');
            window.location.replace('/admin/login');
        });
    }

    // ----------------------------------------------------
    // Load Publication and Draft Data
    // ----------------------------------------------------
    async function loadPublicationData() {
        try {
            let res;
            try {
                res = await fetch('../api/article.php?admin=1', { cache: 'no-cache' });
            } catch (e) {
                res = await fetch('../api/article?admin=1', { cache: 'no-cache' });
            }

            if (!res.ok) {
                if (res.status === 401) {
                    window.location.replace('/admin/login');
                    return;
                }
                throw new Error(`HTTP ${res.status}`);
            }

            const data = await res.json();
            currentPublishedData = data.published || null;
            currentDraftData = data.draft || null;

            renderOverview();
        } catch (error) {
            console.error('Error loading publications:', error);
            showToast('Failed to load publication status. Please refresh.', true);
        }
    }

    function renderOverview() {
        // Draft Banner
        if (currentDraftData) {
            draftAlertBanner.style.display = 'flex';
            const draftTime = formatDate(currentDraftData.updated_at);
            draftBannerText.textContent = `You have an unpublished draft ("${currentDraftData.title || 'Untitled'}") saved at ${draftTime}.`;
        } else {
            draftAlertBanner.style.display = 'none';
        }

        // Published Overview
        if (currentPublishedData && currentPublishedData.status === 'published') {
            overviewEmptyDetails.style.display = 'none';
            overviewPublishedDetails.style.display = 'grid';

            overviewBadge.className = 'status-badge published';
            overviewStatusText.textContent = 'Live Published';

            const pubDate = formatDate(currentPublishedData.published_at || currentPublishedData.updated_at);
            overviewLastUpdated.textContent = `Published on ${pubDate}`;

            overviewTitle.textContent = currentPublishedData.title;
            overviewExcerpt.textContent = currentPublishedData.excerpt;
            overviewCategory.textContent = currentPublishedData.category || 'Sustainability';
            overviewAuthor.textContent = currentPublishedData.author || 'Green AVIC';
            overviewDate.textContent = pubDate;
            overviewReadTime.textContent = `${currentPublishedData.reading_time || 3} min read`;

            if (currentPublishedData.cover_image) {
                overviewThumbnail.src = currentPublishedData.cover_image;
                overviewThumbContainer.style.display = 'block';
            } else {
                overviewThumbContainer.style.display = 'none';
            }
        } else {
            // Empty State
            overviewPublishedDetails.style.display = 'none';
            overviewEmptyDetails.style.display = 'block';

            overviewBadge.className = 'status-badge empty';
            overviewStatusText.textContent = 'No Live Publication';
            overviewLastUpdated.textContent = 'Public page is displaying clean waiting state';
        }
    }

    // ----------------------------------------------------
    // Editor State & Form Handling
    // ----------------------------------------------------
    function openEditor(mode = 'new', prefillData = null) {
        overviewSection.style.display = 'none';
        editorSection.style.display = 'block';

        if (mode === 'edit') {
            isEditingExisting = true;
            editorModeHeading.textContent = 'Edit Current Publication';
            editorStatusBadge.textContent = 'Published';
            editorStatusBadge.className = 'status-badge published';
        } else if (mode === 'draft') {
            isEditingExisting = false;
            editorModeHeading.textContent = 'Editing Saved Draft';
            editorStatusBadge.textContent = 'Draft';
            editorStatusBadge.className = 'status-badge draft';
        } else {
            isEditingExisting = false;
            editorModeHeading.textContent = 'Write New Article';
            editorStatusBadge.textContent = 'New';
            editorStatusBadge.className = 'status-badge draft';
        }

        // Populate fields
        if (prefillData) {
            postTitle.value = prefillData.title || '';
            postCategory.value = prefillData.category || 'Sustainability & Eco-Impact';
            postAuthor.value = prefillData.author || 'Green AVIC';
            postExcerpt.value = prefillData.excerpt || '';
            postContentArea.innerHTML = prefillData.body || '';

            if (prefillData.cover_image) {
                setCoverImage(prefillData.cover_image, prefillData.cover_alt || '');
            } else {
                clearCoverImage();
            }
        } else {
            postTitle.value = '';
            postCategory.value = 'Sustainability & Eco-Impact';
            postAuthor.value = 'Green AVIC';
            postExcerpt.value = '';
            postContentArea.innerHTML = '';
            clearCoverImage();
        }

        updateCounters();
        editorSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closeEditor() {
        editorSection.style.display = 'none';
        overviewSection.style.display = 'block';
        overviewSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Action button listeners in Overview
    if (btnWriteNewPost) {
        btnWriteNewPost.addEventListener('click', () => openEditor('new'));
    }

    if (btnEmptyCreate) {
        btnEmptyCreate.addEventListener('click', () => openEditor('new'));
    }

    if (btnEditCurrent) {
        btnEditCurrent.addEventListener('click', () => {
            if (currentPublishedData) {
                openEditor('edit', currentPublishedData);
            }
        });
    }

    if (btnPreviewCurrent) {
        btnPreviewCurrent.addEventListener('click', () => {
            if (currentPublishedData) {
                showPreviewModal(currentPublishedData);
            }
        });
    }

    if (btnResumeDraft) {
        btnResumeDraft.addEventListener('click', () => {
            if (currentDraftData) {
                openEditor('draft', currentDraftData);
            }
        });
    }

    if (btnDiscardDraftAlert) {
        btnDiscardDraftAlert.addEventListener('click', () => {
            draftAlertBanner.style.display = 'none';
        });
    }

    if (btnCancelEditor) {
        btnCancelEditor.addEventListener('click', () => {
            if (confirm('Return to dashboard? Any unsaved edits in this form will be discarded unless you save a draft.')) {
                closeEditor();
            }
        });
    }

    if (btnDiscardEditor) {
        btnDiscardEditor.addEventListener('click', () => {
            if (confirm('Discard changes and return to overview?')) {
                closeEditor();
            }
        });
    }

    // ----------------------------------------------------
    // Character and Word Counters
    // ----------------------------------------------------
    function updateCounters() {
        if (titleCharCount) {
            titleCharCount.textContent = `${postTitle.value.length} / 140`;
        }
        if (excerptCharCount) {
            excerptCharCount.textContent = `${postExcerpt.value.length} / 280`;
        }
        if (bodyWordCount) {
            const text = postContentArea.innerText || '';
            const words = text.trim() ? text.trim().split(/\s+/).length : 0;
            bodyWordCount.textContent = `${words} words`;
        }
    }

    postTitle.addEventListener('input', updateCounters);
    postExcerpt.addEventListener('input', updateCounters);
    postContentArea.addEventListener('input', updateCounters);

    // ----------------------------------------------------
    // Cover Image Upload & Drag-and-Drop
    // ----------------------------------------------------
    function setCoverImage(url, alt = '') {
        postCoverImage.value = url;
        uploadPreviewImg.src = url;
        previewFileName.textContent = url.split('/').pop();
        uploadDropzone.style.display = 'none';
        uploadPreviewContainer.style.display = 'block';
        coverAltGroup.style.display = 'block';
        if (alt) {
            postCoverAlt.value = alt;
        }
    }

    function clearCoverImage() {
        postCoverImage.value = '';
        uploadPreviewImg.src = '';
        coverFileInput.value = '';
        uploadDropzone.style.display = 'block';
        uploadPreviewContainer.style.display = 'none';
        coverAltGroup.style.display = 'none';
        postCoverAlt.value = '';
    }

    uploadDropzone.addEventListener('click', (e) => {
        if (e.target !== coverFileInput) {
            coverFileInput.click();
        }
    });

    uploadDropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadDropzone.classList.add('dragover');
    });

    uploadDropzone.addEventListener('dragleave', () => {
        uploadDropzone.classList.remove('dragover');
    });

    uploadDropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadDropzone.classList.remove('dragover');
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            handleFileUpload(e.dataTransfer.files[0]);
        }
    });

    coverFileInput.addEventListener('change', () => {
        if (coverFileInput.files && coverFileInput.files.length > 0) {
            handleFileUpload(coverFileInput.files[0]);
        }
    });

    if (btnReplaceImage) {
        btnReplaceImage.addEventListener('click', () => {
            coverFileInput.click();
        });
    }

    if (btnRemoveImage) {
        btnRemoveImage.addEventListener('click', () => {
            clearCoverImage();
        });
    }

    async function handleFileUpload(file) {
        // Validate client size (5MB max)
        const maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
            showToast('Image exceeds 5MB size limit. Please choose a smaller image.', true);
            return;
        }

        // Validate format
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            showToast('Unsupported format. Please upload JPG, PNG, or WebP.', true);
            return;
        }

        // Show progress animation
        uploadProgressBar.style.display = 'block';
        uploadProgressFill.style.width = '30%';

        const formData = new FormData();
        formData.append('image', file);
        formData.append('csrf_token', csrfToken);

        try {
            uploadProgressFill.style.width = '60%';

            let res;
            try {
                res = await fetch('../api/upload.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                });
            } catch (err) {
                res = await fetch('../api/upload', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                });
            }

            uploadProgressFill.style.width = '90%';
            const data = await res.json();

            if (res.ok && data.success) {
                uploadProgressFill.style.width = '100%';
                setTimeout(() => {
                    uploadProgressBar.style.display = 'none';
                    uploadProgressFill.style.width = '0%';
                }, 300);

                setCoverImage(data.url, postCoverAlt.value);
                showToast('Cover image uploaded successfully!');
            } else {
                uploadProgressBar.style.display = 'none';
                uploadProgressFill.style.width = '0%';
                showToast(data.error || 'Failed to upload image.', true);
            }
        } catch (error) {
            uploadProgressBar.style.display = 'none';
            uploadProgressFill.style.width = '0%';
            console.error('Upload error:', error);
            showToast('Network error during image upload.', true);
        }
    }

    // ----------------------------------------------------
    // Rich Text Editor Toolbar Actions
    // ----------------------------------------------------
    const toolbarButtons = document.querySelectorAll('.toolbar-btn[data-command]');
    toolbarButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const cmd = btn.getAttribute('data-command');
            const val = btn.getAttribute('data-val') || null;

            postContentArea.focus();
            document.execCommand(cmd, false, val);
            updateCounters();
        });
    });

    const btnInsertLink = document.getElementById('btnInsertLink');
    if (btnInsertLink) {
        btnInsertLink.addEventListener('click', (e) => {
            e.preventDefault();
            const url = prompt('Enter the link destination URL (e.g. https://example.com):');
            if (url) {
                let safeUrl = url.trim();
                if (!safeUrl.startsWith('http://') && !safeUrl.startsWith('https://') && !safeUrl.startsWith('mailto:')) {
                    safeUrl = 'https://' + safeUrl;
                }
                postContentArea.focus();
                document.execCommand('createLink', false, safeUrl);
                updateCounters();
            }
        });
    }

    // ----------------------------------------------------
    // Save Draft Action
    // ----------------------------------------------------
    if (btnSaveDraft) {
        btnSaveDraft.addEventListener('click', async () => {
            const title = postTitle.value.trim();
            const excerpt = postExcerpt.value.trim();
            const body = postContentArea.innerHTML.trim();
            const category = postCategory.value;
            const author = postAuthor.value.trim() || 'Green AVIC';
            const coverImage = postCoverImage.value;
            const coverAlt = postCoverAlt.value.trim();

            if (!title && !body) {
                showToast('Draft must contain at least a title or body text.', true);
                return;
            }

            btnSaveDraft.disabled = true;
            btnSaveDraft.innerHTML = '<span>Saving...</span>';

            const payload = {
                action: 'save_draft',
                csrf_token: csrfToken,
                title: title,
                excerpt: excerpt,
                body: body,
                category: category,
                author: author,
                cover_image: coverImage,
                cover_alt: coverAlt
            };

            try {
                let res;
                try {
                    res = await fetch('../api/article.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                        body: JSON.stringify(payload)
                    });
                } catch (e) {
                    res = await fetch('../api/article', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                        body: JSON.stringify(payload)
                    });
                }

                const data = await res.json();
                if (res.ok && data.success) {
                    currentDraftData = data.draft;
                    showToast('Draft saved successfully! You can resume editing anytime.');
                } else {
                    showToast(data.error || 'Failed to save draft.', true);
                }
            } catch (err) {
                console.error('Save draft error:', err);
                showToast('Connection error while saving draft.', true);
            } finally {
                btnSaveDraft.disabled = false;
                btnSaveDraft.innerHTML = `
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg>
                    <span>Save Draft</span>
                `;
            }
        });
    }

    // ----------------------------------------------------
    // Preview Modal
    // ----------------------------------------------------
    function showPreviewModal(articleData) {
        const coverHtml = articleData.cover_image ? `
            <figure style="margin: 20px 0 24px; border-radius: 8px; overflow: hidden;">
                <img src="${articleData.cover_image}" alt="${articleData.cover_alt || ''}" style="width: 100%; max-height: 400px; object-fit: cover; display: block;">
                ${articleData.cover_alt ? `<figcaption style="font-size: 0.8rem; color: #888; padding: 6px 0; font-style: italic;">${articleData.cover_alt}</figcaption>` : ''}
            </figure>
        ` : '';

        const dateStr = formatDate(articleData.published_at || new Date().toISOString());

        previewModalBody.innerHTML = `
            <div style="max-width: 760px; margin: 0 auto; font-family: 'Open Sans', sans-serif;">
                <div style="display: flex; gap: 8px; align-items: center; font-size: 0.85rem; color: #666; margin-bottom: 12px;">
                    <span style="background: #E8F5E9; color: #2E7D32; font-weight: 700; font-size: 0.75rem; padding: 3px 10px; border-radius: 12px; text-transform: uppercase;">
                        ${articleData.category || 'Sustainability'}
                    </span>
                    <span>•</span>
                    <time>${dateStr}</time>
                    <span>•</span>
                    <span>${articleData.reading_time || 3} min read</span>
                </div>
                <h1 style="font-size: 2.2rem; color: #0D3D2F; line-height: 1.3; margin-bottom: 16px;">
                    ${articleData.title || 'Untitled Article'}
                </h1>
                <p style="font-size: 1.15rem; color: #555; line-height: 1.6; border-left: 3px solid #4CAF50; padding-left: 14px; margin-bottom: 20px;">
                    ${articleData.excerpt || ''}
                </p>
                <div style="font-size: 0.9rem; color: #777; margin-bottom: 20px;">
                    By <strong>${articleData.author || 'Green AVIC'}</strong>
                </div>
                ${coverHtml}
                <div style="font-size: 1.05rem; line-height: 1.8; color: #333;" class="reader-body-preview">
                    ${articleData.body || '<p><em>No body content provided.</em></p>'}
                </div>
            </div>
        `;

        previewModal.style.display = 'flex';
    }

    if (btnPreviewEditor) {
        btnPreviewEditor.addEventListener('click', () => {
            const tempArticle = {
                title: postTitle.value.trim() || 'Untitled Article',
                excerpt: postExcerpt.value.trim() || 'No excerpt entered yet.',
                body: postContentArea.innerHTML.trim() || '<p>Write some text in the body to preview.</p>',
                category: postCategory.value,
                author: postAuthor.value.trim() || 'Green AVIC',
                cover_image: postCoverImage.value,
                cover_alt: postCoverAlt.value.trim(),
                published_at: new Date().toISOString(),
                reading_time: Math.max(1, Math.ceil((postContentArea.innerText || '').trim().split(/\s+/).length / 200))
            };
            showPreviewModal(tempArticle);
        });
    }

    if (btnClosePreviewModal) {
        btnClosePreviewModal.addEventListener('click', () => { previewModal.style.display = 'none'; });
    }
    if (btnClosePreviewModalBottom) {
        btnClosePreviewModalBottom.addEventListener('click', () => { previewModal.style.display = 'none'; });
    }

    // ----------------------------------------------------
    // Publish Action & Confirmation Modal
    // ----------------------------------------------------
    function validateForPublish() {
        const title = postTitle.value.trim();
        const excerpt = postExcerpt.value.trim();
        const bodyText = postContentArea.innerText.trim();
        const coverImage = postCoverImage.value;
        const coverAlt = postCoverAlt.value.trim();

        if (!title) {
            showToast('Please enter an article headline.', true);
            postTitle.focus();
            return false;
        }

        if (!excerpt) {
            showToast('Please provide a short introduction or excerpt.', true);
            postExcerpt.focus();
            return false;
        }

        if (bodyText.length < 20) {
            showToast('Article body must be at least 20 characters long.', true);
            postContentArea.focus();
            return false;
        }

        if (coverImage && !coverAlt) {
            showToast('Please provide descriptive Alt Text for the cover image (for accessibility).', true);
            postCoverAlt.focus();
            return false;
        }

        return true;
    }

    if (btnPublishPost) {
        btnPublishPost.addEventListener('click', () => {
            if (!validateForPublish()) return;

            // If an article is already published, show confirmation dialog
            if (currentPublishedData && currentPublishedData.status === 'published') {
                confirmModal.style.display = 'flex';
            } else {
                executePublish();
            }
        });
    }

    if (btnCancelConfirm) {
        btnCancelConfirm.addEventListener('click', () => { confirmModal.style.display = 'none'; });
    }
    if (btnCancelConfirmTop) {
        btnCancelConfirmTop.addEventListener('click', () => { confirmModal.style.display = 'none'; });
    }

    if (btnProceedPublish) {
        btnProceedPublish.addEventListener('click', () => {
            confirmModal.style.display = 'none';
            executePublish();
        });
    }

    async function executePublish() {
        btnPublishPost.disabled = true;
        btnPublishPost.innerHTML = '<span>Publishing Live...</span>';

        const payload = {
            action: 'publish',
            is_edit: isEditingExisting,
            csrf_token: csrfToken,
            title: postTitle.value.trim(),
            excerpt: postExcerpt.value.trim(),
            body: postContentArea.innerHTML.trim(),
            category: postCategory.value,
            author: postAuthor.value.trim() || 'Green AVIC',
            cover_image: postCoverImage.value,
            cover_alt: postCoverAlt.value.trim()
        };

        try {
            let res;
            try {
                res = await fetch('../api/article.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
            } catch (err) {
                res = await fetch('../api/article', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
            }

            const data = await res.json();

            if (res.ok && data.success) {
                currentPublishedData = data.article;
                currentDraftData = null; // Cleared on successful publish
                showToast('Article published live successfully! The website is updated.');
                closeEditor();
                renderOverview();
            } else {
                showToast(data.error || 'Failed to publish article. Existing publication preserved.', true);
            }
        } catch (error) {
            console.error('Publish error:', error);
            showToast('Network error while publishing. Existing publication preserved.', true);
        } finally {
            btnPublishPost.disabled = false;
            btnPublishPost.innerHTML = `
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 2L11 13"></path>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
                <span>Publish Article</span>
            `;
        }
    }

    // Close modals on clicking overlay background
    window.addEventListener('click', (e) => {
        if (e.target === previewModal) previewModal.style.display = 'none';
        if (e.target === confirmModal) confirmModal.style.display = 'none';
    });

    // ----------------------------------------------------
    // Initial Verification Call
    // ----------------------------------------------------
    verifyAuth();
});
