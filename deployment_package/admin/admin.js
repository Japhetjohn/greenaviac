/**
 * Green AVIC — Content Studio Admin Script
 * MariaDB Single-Publication CMS Client with Mandatory First-Login Password Change
 */

// ----------------------------------------------------
// Global Utilities & Client-Side Error Reporting
// ----------------------------------------------------
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

window.addEventListener('error', (event) => {
    try {
        fetch('api/logs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'client_log',
                level: 'error',
                message: event.message || 'Client JavaScript error',
                context: {
                    filename: event.filename,
                    lineno: event.lineno,
                    colno: event.colno,
                    stack: event.error ? event.error.stack : null
                }
            })
        }).catch(() => {});
    } catch (e) {}
});

window.addEventListener('unhandledrejection', (event) => {
    try {
        fetch('api/logs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'client_log',
                level: 'warning',
                message: 'Unhandled Promise Rejection: ' + (event.reason ? (event.reason.message || String(event.reason)) : 'unknown'),
                context: {
                    stack: event.reason && event.reason.stack ? event.reason.stack : null
                }
            })
        }).catch(() => {});
    } catch (e) {}
});

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

    // Password Change Modal
    const passwordChangeModal = document.getElementById('passwordChangeModal');
    const pwdChangeForm = document.getElementById('pwdChangeForm');
    const pwdChangeAlert = document.getElementById('pwdChangeAlert');
    const currentPass = document.getElementById('currentPass');
    const newPass = document.getElementById('newPass');
    const confirmPass = document.getElementById('confirmPass');
    const btnSubmitNewPass = document.getElementById('btnSubmitNewPass');

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

    // ----------------------------------------------------
    // DOM Elements - Modals & Dialogs
    // ----------------------------------------------------
    const previewModal = document.getElementById('previewModal');
    const previewModalBody = document.getElementById('previewModalBody');
    const btnClosePreviewModal = document.getElementById('btnClosePreviewModal');
    const btnClosePreviewModalBottom = document.getElementById('btnClosePreviewModalBottom');

    const confirmModal = document.getElementById('confirmModal');
    const btnCancelConfirm = document.getElementById('btnCancelConfirm');
    const btnCancelConfirmTop = document.getElementById('btnCancelConfirmTop');
    const btnProceedPublish = document.getElementById('btnProceedPublish');

    // Team & Administrators Elements
    const btnManageAdmins = document.getElementById('btnManageAdmins');
    const navAdminsCount = document.getElementById('navAdminsCount');
    const overviewAdminBadge = document.getElementById('overviewAdminBadge');
    const overviewAdminBadgeCount = document.getElementById('overviewAdminBadgeCount');
    const adminsModal = document.getElementById('adminsModal');
    const modalAdminsCount = document.getElementById('modalAdminsCount');
    const btnCloseAdminsModal = document.getElementById('btnCloseAdminsModal');
    const btnCloseAdminsModalBottom = document.getElementById('btnCloseAdminsModalBottom');
    const adminsListContainer = document.getElementById('adminsListContainer');
    const btnGenerateInvite = document.getElementById('btnGenerateInvite');
    const inviteLinkInput = document.getElementById('inviteLinkInput');
    const btnCopyInviteLink = document.getElementById('btnCopyInviteLink');
    const formAddAdminDirect = document.getElementById('formAddAdminDirect');
    const btnRefreshAdmins = document.getElementById('btnRefreshAdmins');

    // Subscribers & Broadcasts Elements
    const btnManageSubscribers = document.getElementById('btnManageSubscribers');
    const overviewAudienceBadge = document.getElementById('overviewAudienceBadge');
    const overviewSubscribersCount = document.getElementById('overviewSubscribersCount');
    const subscribersModal = document.getElementById('subscribersModal');
    const btnCloseSubscribersModal = document.getElementById('btnCloseSubscribersModal');
    const btnCloseSubscribersModalBottom = document.getElementById('btnCloseSubscribersModalBottom');
    const statActiveSubscribers = document.getElementById('statActiveSubscribers');
    const statPendingSubscribers = document.getElementById('statPendingSubscribers');
    const statUnsubscribed = document.getElementById('statUnsubscribed');
    const statQuotaUsed = document.getElementById('statQuotaUsed');
    const statQuotaAvail = document.getElementById('statQuotaAvail');
    const statSmtpStatus = document.getElementById('statSmtpStatus');
    const statSmtpDesc = document.getElementById('statSmtpDesc');
    const campaignCard = document.getElementById('campaignCard');
    const campaignStatusBadge = document.getElementById('campaignStatusBadge');
    const campaignDetails = document.getElementById('campaignDetails');
    const btnProcessBatch = document.getElementById('btnProcessBatch');
    const btnRetryCampaign = document.getElementById('btnRetryCampaign');
    const cronStatusPill = document.getElementById('cronStatusPill');
    const cronCommandText = document.getElementById('cronCommandText');
    const btnTestSmtp = document.getElementById('btnTestSmtp');
    const subscribersTableBody = document.getElementById('subscribersTableBody');
    const formAddSubscriberDirect = document.getElementById('formAddSubscriberDirect');
    const newSubEmail = document.getElementById('newSubEmail');

    // System Health & Audit Logs Elements
    const btnHealthLogs = document.getElementById('btnHealthLogs');
    const healthLogsModal = document.getElementById('healthLogsModal');
    const btnCloseHealthLogsModal = document.getElementById('btnCloseHealthLogsModal');
    const btnCloseHealthLogsModalBottom = document.getElementById('btnCloseHealthLogsModalBottom');
    const tabBtnHealth = document.getElementById('tabBtnHealth');
    const tabBtnLogs = document.getElementById('tabBtnLogs');
    const tabContentHealth = document.getElementById('tabContentHealth');
    const tabContentLogs = document.getElementById('tabContentLogs');
    const btnRefreshHealth = document.getElementById('btnRefreshHealth');
    const btnRepairDatabase = document.getElementById('btnRepairDatabase');
    const btnFilterLogs = document.getElementById('btnFilterLogs');
    const logLevelFilter = document.getElementById('logLevelFilter');
    const logSearchInput = document.getElementById('logSearchInput');
    const logsTableBody = document.getElementById('logsTableBody');
    const healthDbBadge = document.getElementById('healthDbBadge');
    const healthDbName = document.getElementById('healthDbName');
    const healthDbLatency = document.getElementById('healthDbLatency');
    const healthDbSchema = document.getElementById('healthDbSchema');
    const healthSmtpBadge = document.getElementById('healthSmtpBadge');
    const healthSmtpConfigured = document.getElementById('healthSmtpConfigured');
    const healthSmtpTested = document.getElementById('healthSmtpTested');
    const healthSmtpVerified = document.getElementById('healthSmtpVerified');
    const healthQueueBadge = document.getElementById('healthQueueBadge');
    const healthActiveSubs = document.getElementById('healthActiveSubs');
    const healthPendingDeliv = document.getElementById('healthPendingDeliv');
    const healthFailedDeliv = document.getElementById('healthFailedDeliv');
    const healthRuntimeBadge = document.getElementById('healthRuntimeBadge');
    const healthMemory = document.getElementById('healthMemory');
    const healthDisk = document.getElementById('healthDisk');
    const healthServerTime = document.getElementById('healthServerTime');

    // ----------------------------------------------------
    // Modal Management Helpers
    // ----------------------------------------------------
    function openModal(modal) {
        if (!modal) return;
        closeMobileDrawer();
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    function setupModalClose(modal, ...closeButtons) {
        if (!modal) return;
        closeButtons.forEach(btn => {
            if (btn) {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    closeModal(modal);
                });
            }
        });
    }

    // Attach universal close handlers to all modals
    setupModalClose(previewModal, btnClosePreviewModal, btnClosePreviewModalBottom);
    setupModalClose(confirmModal, btnCancelConfirm, btnCancelConfirmTop);
    setupModalClose(adminsModal, btnCloseAdminsModal, btnCloseAdminsModalBottom);
    setupModalClose(subscribersModal, btnCloseSubscribersModal, btnCloseSubscribersModalBottom);
    setupModalClose(healthLogsModal, btnCloseHealthLogsModal, btnCloseHealthLogsModalBottom);

    // ----------------------------------------------------
    // Mobile Drawer Navigation Handling
    // ----------------------------------------------------
    const btnMobileMenuToggle = document.getElementById('btnMobileMenuToggle');
    const mobileNavBackdrop = document.getElementById('mobileNavBackdrop');
    const mobileNavDrawer = document.getElementById('mobileNavDrawer');
    const btnCloseMobileNav = document.getElementById('btnCloseMobileNav');
    const adminEmailDisplayMobile = document.getElementById('adminEmailDisplayMobile');
    const btnOpenPasswordModalMobile = document.getElementById('btnOpenPasswordModalMobile');
    const btnManageSubscribersMobile = document.getElementById('btnManageSubscribersMobile');
    const btnManageAdminsMobile = document.getElementById('btnManageAdminsMobile');
    const btnHealthLogsMobile = document.getElementById('btnHealthLogsMobile');
    const btnLogoutMobile = document.getElementById('btnLogoutMobile');
    const navAdminsCountMobile = document.getElementById('navAdminsCountMobile');
    const mobileAudienceCountBadge = document.getElementById('mobileAudienceCountBadge');

    function openMobileDrawer() {
        if (mobileNavDrawer) {
            mobileNavDrawer.classList.add('open');
            mobileNavDrawer.setAttribute('aria-hidden', 'false');
        }
        if (mobileNavBackdrop) {
            mobileNavBackdrop.style.display = 'block';
            setTimeout(() => {
                if (mobileNavBackdrop) mobileNavBackdrop.classList.add('show');
            }, 10);
            mobileNavBackdrop.setAttribute('aria-hidden', 'false');
        }
        if (btnMobileMenuToggle) {
            btnMobileMenuToggle.classList.add('active');
            btnMobileMenuToggle.setAttribute('aria-expanded', 'true');
        }
        document.body.classList.add('drawer-open');
    }

    function closeMobileDrawer() {
        if (mobileNavDrawer) {
            mobileNavDrawer.classList.remove('open');
            mobileNavDrawer.setAttribute('aria-hidden', 'true');
        }
        if (mobileNavBackdrop) {
            mobileNavBackdrop.classList.remove('show');
            setTimeout(() => {
                if (mobileNavBackdrop && (!mobileNavDrawer || !mobileNavDrawer.classList.contains('open'))) {
                    mobileNavBackdrop.style.display = 'none';
                }
            }, 250);
            mobileNavBackdrop.setAttribute('aria-hidden', 'true');
        }
        if (btnMobileMenuToggle) {
            btnMobileMenuToggle.classList.remove('active');
            btnMobileMenuToggle.setAttribute('aria-expanded', 'false');
        }
        document.body.classList.remove('drawer-open');
    }

    if (btnMobileMenuToggle) {
        btnMobileMenuToggle.addEventListener('click', (e) => {
            e.preventDefault();
            if (mobileNavDrawer && mobileNavDrawer.classList.contains('open')) {
                closeMobileDrawer();
            } else {
                openMobileDrawer();
            }
        });
    }

    if (btnCloseMobileNav) {
        btnCloseMobileNav.addEventListener('click', (e) => {
            e.preventDefault();
            closeMobileDrawer();
        });
    }

    if (mobileNavBackdrop) {
        mobileNavBackdrop.addEventListener('click', () => {
            closeMobileDrawer();
        });
    }

    // Close mobile nav drawer with Escape key
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && mobileNavDrawer && mobileNavDrawer.classList.contains('open')) {
            closeMobileDrawer();
        }
    });

    // Auto-close drawer if screen resized beyond mobile breakpoint
    window.addEventListener('resize', () => {
        if (window.innerWidth > 900 && mobileNavDrawer && mobileNavDrawer.classList.contains('open')) {
            closeMobileDrawer();
        }
    });

    if (btnManageSubscribersMobile) {
        btnManageSubscribersMobile.addEventListener('click', (e) => {
            e.preventDefault();
            closeMobileDrawer();
            openModal(subscribersModal);
            loadSubscribersData();
        });
    }

    if (btnManageAdminsMobile) {
        btnManageAdminsMobile.addEventListener('click', (e) => {
            e.preventDefault();
            closeMobileDrawer();
            openModal(adminsModal);
            loadAdminsList();
        });
    }

    if (btnHealthLogsMobile) {
        btnHealthLogsMobile.addEventListener('click', (e) => {
            e.preventDefault();
            closeMobileDrawer();
            openModal(healthLogsModal);
            loadSystemHealth();
            loadSystemLogs();
        });
    }

    if (btnOpenPasswordModalMobile) {
        btnOpenPasswordModalMobile.addEventListener('click', (e) => {
            e.preventDefault();
            closeMobileDrawer();
            showPasswordChangeModal(false);
        });
    }

    if (btnLogoutMobile) {
        btnLogoutMobile.addEventListener('click', (e) => {
            e.preventDefault();
            closeMobileDrawer();
            if (btnLogout) {
                btnLogout.click();
            }
        });
    }

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

    function formatDate(dateStr) {
        if (!dateStr) return '';
        try {
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return dateStr;
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (e) {
            return dateStr;
        }
    }

    // ----------------------------------------------------
    // Authentication Check & Password Change Flow
    // ----------------------------------------------------
    async function verifyAuth() {
        try {
            const res = await fetch('api/auth.php?action=check', { cache: 'no-cache' });
            if (!res.ok) {
                window.location.replace('login');
                return;
            }

            const data = await res.json();
            if (!data || !data.authenticated) {
                window.location.replace('login');
                return;
            }

            if (data.email) {
                if (adminEmailDisplay) adminEmailDisplay.textContent = data.email;
                if (adminEmailDisplayMobile) adminEmailDisplayMobile.textContent = data.email;
            }

            if (data.csrf_token) {
                csrfToken = data.csrf_token;
                sessionStorage.setItem('greenavic_csrf', csrfToken);
            }

            // Check if mandatory password change is required
            if (data.must_change_password) {
                showPasswordChangeModal(true);
                return;
            }

            // Otherwise, load publication records
            loadPublicationData();
        } catch (e) {
            console.error('Auth verification error:', e);
            window.location.replace('login');
        }
    }

    const btnClosePasswordModal = document.getElementById('btnClosePasswordModal');
    if (btnClosePasswordModal) {
        btnClosePasswordModal.addEventListener('click', (e) => {
            e.preventDefault();
            closeModal(passwordChangeModal);
        });
    }

    function showPasswordChangeModal(isMandatory = false) {
        if (!passwordChangeModal) return;
        closeMobileDrawer();
        const pwdTitle = document.getElementById('pwdChangeTitle');
        if (pwdTitle) {
            pwdTitle.textContent = isMandatory ? 'Password Change Required' : 'Change Account Password';
        }
        if (btnClosePasswordModal) {
            btnClosePasswordModal.style.display = isMandatory ? 'none' : 'flex';
        }
        passwordChangeModal.style.display = 'flex';
        passwordChangeModal.setAttribute('aria-hidden', 'false');
    }

    if (pwdChangeForm) {
        pwdChangeForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            pwdChangeAlert.style.display = 'none';

            const cur = currentPass.value;
            const np = newPass.value;
            const cp = confirmPass.value;

            if (!cur || !np || !cp) {
                pwdChangeAlert.textContent = 'Please fill in all password fields.';
                pwdChangeAlert.style.background = '#FFEBEE';
                pwdChangeAlert.style.color = '#D32F2F';
                pwdChangeAlert.style.display = 'block';
                return;
            }

            if (np !== cp) {
                pwdChangeAlert.textContent = 'New password and confirmation do not match.';
                pwdChangeAlert.style.background = '#FFEBEE';
                pwdChangeAlert.style.color = '#D32F2F';
                pwdChangeAlert.style.display = 'block';
                return;
            }

            if (np.length < 8) {
                pwdChangeAlert.textContent = 'New password must be at least 8 characters long.';
                pwdChangeAlert.style.background = '#FFEBEE';
                pwdChangeAlert.style.color = '#D32F2F';
                pwdChangeAlert.style.display = 'block';
                return;
            }

            btnSubmitNewPass.disabled = true;
            btnSubmitNewPass.textContent = 'Updating Password...';

            try {
                const res = await fetch('api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        action: 'change_password',
                        csrf_token: csrfToken,
                        current_password: cur,
                        new_password: np,
                        confirm_password: cp
                    })
                });

                const data = await res.json();

                if (res.ok && data.success) {
                    passwordChangeModal.style.display = 'none';
                    sessionStorage.removeItem('greenavic_must_change');
                    showToast('Permanent password saved successfully! Welcome.');
                    loadPublicationData();
                } else {
                    pwdChangeAlert.textContent = data.error || 'Failed to update password.';
                    pwdChangeAlert.style.background = '#FFEBEE';
                    pwdChangeAlert.style.color = '#D32F2F';
                    pwdChangeAlert.style.display = 'block';
                    btnSubmitNewPass.disabled = false;
                    btnSubmitNewPass.textContent = 'Set New Password & Enter Studio →';
                }
            } catch (err) {
                pwdChangeAlert.textContent = 'Network error while updating password.';
                pwdChangeAlert.style.background = '#FFEBEE';
                pwdChangeAlert.style.color = '#D32F2F';
                pwdChangeAlert.style.display = 'block';
                btnSubmitNewPass.disabled = false;
                btnSubmitNewPass.textContent = 'Set New Password & Enter Studio →';
            }
        });
    }

    // Logout handler
    if (btnLogout) {
        btnLogout.addEventListener('click', async () => {
            try {
                await fetch('api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'logout', csrf_token: csrfToken })
                });
            } catch (e) {}
            sessionStorage.removeItem('greenavic_csrf');
            window.location.replace('login');
        });
    }

    // ----------------------------------------------------
    // Load Publication and Draft Data
    // ----------------------------------------------------
    async function loadPublicationData() {
        try {
            const res = await fetch('api/article.php?admin=1', { cache: 'no-cache' });
            if (!res.ok) {
                if (res.status === 401) {
                    window.location.replace('login');
                    return;
                }
                throw new Error(`HTTP ${res.status}`);
            }

            const data = await res.json();
            currentPublishedData = data.published || null;
            currentDraftData = data.draft || null;

            renderOverview();
            loadSubscriberAudienceSummary();
            loadAdminCountSummary();
        } catch (error) {
            console.error('Error loading publications:', error);
            showToast('Failed to load publication status. Please refresh.', true);
        }
    }

    function renderOverview() {
        // Draft Alert Banner
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
            overviewCategory.textContent = currentPublishedData.category || 'Sustainability & Eco-Impact';
            overviewAuthor.textContent = currentPublishedData.author || 'Green AVIC';
            overviewDate.textContent = pubDate;
            overviewReadTime.textContent = `${currentPublishedData.reading_time || 3} min read`;

            if (currentPublishedData.cover_image) {
                const fallbackThumbnail = 'assets/images/brand/greenavic-placeholder.svg';
                overviewThumbnail.onerror = function() {
                    this.onerror = null;
                    this.src = fallbackThumbnail;
                };
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
            overviewLastUpdated.textContent = 'Public newsletter is displaying clean waiting state';
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

    if (btnWriteNewPost) btnWriteNewPost.addEventListener('click', () => openEditor('new'));
    if (btnEmptyCreate) btnEmptyCreate.addEventListener('click', () => openEditor('new'));
    if (btnEditCurrent) {
        btnEditCurrent.addEventListener('click', () => {
            if (currentPublishedData) openEditor('edit', currentPublishedData);
        });
    }
    if (btnPreviewCurrent) {
        btnPreviewCurrent.addEventListener('click', () => {
            if (currentPublishedData) showPreviewModal(currentPublishedData);
        });
    }
    if (btnResumeDraft) {
        btnResumeDraft.addEventListener('click', () => {
            if (currentDraftData) openEditor('draft', currentDraftData);
        });
    }
    if (btnDiscardDraftAlert) {
        btnDiscardDraftAlert.addEventListener('click', () => {
            draftAlertBanner.style.display = 'none';
        });
    }
    if (btnCancelEditor) {
        btnCancelEditor.addEventListener('click', () => {
            if (confirm('Return to dashboard? Unsaved edits will be discarded unless saved as draft.')) {
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
        if (titleCharCount) titleCharCount.textContent = `${postTitle.value.length} / 140`;
        if (excerptCharCount) excerptCharCount.textContent = `${postExcerpt.value.length} / 280`;
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
        const fallback = 'assets/images/brand/greenavic-placeholder.svg';
        uploadPreviewImg.onerror = function() {
            this.onerror = null;
            this.src = fallback;
        };
        postCoverImage.value = url;
        uploadPreviewImg.src = url;
        previewFileName.textContent = url.split('/').pop();
        uploadDropzone.style.display = 'none';
        uploadPreviewContainer.style.display = 'block';
        coverAltGroup.style.display = 'block';
        if (alt) postCoverAlt.value = alt;
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
        if (e.target !== coverFileInput) coverFileInput.click();
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

    if (btnReplaceImage) btnReplaceImage.addEventListener('click', () => coverFileInput.click());
    if (btnRemoveImage) btnRemoveImage.addEventListener('click', () => clearCoverImage());

    async function handleFileUpload(file) {
        if (file.size > 5 * 1024 * 1024) {
            showToast('Image exceeds 5MB size limit.', true);
            return;
        }

        const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowed.includes(file.type)) {
            showToast('Unsupported format. Please upload JPG, PNG, or WebP.', true);
            return;
        }

        uploadProgressBar.style.display = 'block';
        uploadProgressFill.style.width = '30%';

        const formData = new FormData();
        formData.append('image', file);
        formData.append('csrf_token', csrfToken);

        try {
            uploadProgressFill.style.width = '60%';

            const res = await fetch('api/upload.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: formData
            });

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
            showToast('Network error during image upload.', true);
        }
    }

    // ----------------------------------------------------
    // Rich Text Editor Toolbar
    // ----------------------------------------------------
    document.querySelectorAll('.toolbar-btn[data-command]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            postContentArea.focus();
            document.execCommand(btn.getAttribute('data-command'), false, btn.getAttribute('data-val') || null);
            updateCounters();
        });
    });

    const btnInsertLink = document.getElementById('btnInsertLink');
    if (btnInsertLink) {
        btnInsertLink.addEventListener('click', (e) => {
            e.preventDefault();
            const url = prompt('Enter link URL (e.g. https://example.com):');
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

            try {
                const res = await fetch('api/article.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        action: 'save_draft',
                        csrf_token: csrfToken,
                        title, excerpt, body, category, author,
                        cover_image: coverImage,
                        cover_alt: coverAlt
                    })
                });

                const data = await res.json();
                if (res.ok && data.success) {
                    currentDraftData = data.draft;
                    showToast('Draft saved successfully! You can resume editing anytime.');
                } else {
                    showToast(data.error || 'Failed to save draft.', true);
                }
            } catch (err) {
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
                <div style="font-size: 1.05rem; line-height: 1.8; color: #333;">
                    ${articleData.body || '<p><em>No body content provided.</em></p>'}
                </div>
            </div>
        `;

        previewModal.style.display = 'flex';
    }

    if (btnPreviewEditor) {
        btnPreviewEditor.addEventListener('click', () => {
            showPreviewModal({
                title: postTitle.value.trim() || 'Untitled Article',
                excerpt: postExcerpt.value.trim() || 'No excerpt entered.',
                body: postContentArea.innerHTML.trim() || '<p>Write some text in the body to preview.</p>',
                category: postCategory.value,
                author: postAuthor.value.trim() || 'Green AVIC',
                cover_image: postCoverImage.value,
                cover_alt: postCoverAlt.value.trim(),
                published_at: new Date().toISOString(),
                reading_time: Math.max(1, Math.ceil((postContentArea.innerText || '').trim().split(/\s+/).length / 200))
            });
        });
    }

    if (btnClosePreviewModal) btnClosePreviewModal.addEventListener('click', () => { previewModal.style.display = 'none'; });
    if (btnClosePreviewModalBottom) btnClosePreviewModalBottom.addEventListener('click', () => { previewModal.style.display = 'none'; });

    // ----------------------------------------------------
    // Publish Action & Confirmation Modal
    // ----------------------------------------------------
    if (btnPublishPost) {
        btnPublishPost.addEventListener('click', () => {
            const title = postTitle.value.trim();
            const excerpt = postExcerpt.value.trim();
            const bodyText = postContentArea.innerText.trim();
            const coverImage = postCoverImage.value;
            const coverAlt = postCoverAlt.value.trim();

            if (!title) {
                showToast('Please enter an article headline.', true);
                postTitle.focus();
                return;
            }
            if (!excerpt) {
                showToast('Please provide a short introduction or excerpt.', true);
                postExcerpt.focus();
                return;
            }
            if (bodyText.length < 20) {
                showToast('Article body must be at least 20 characters long.', true);
                postContentArea.focus();
                return;
            }
            if (coverImage && !coverAlt) {
                showToast('Please provide descriptive Alt Text for the cover image.', true);
                postCoverAlt.focus();
                return;
            }

            if (currentPublishedData && currentPublishedData.status === 'published') {
                confirmModal.style.display = 'flex';
            } else {
                executePublish();
            }
        });
    }

    if (btnCancelConfirm) btnCancelConfirm.addEventListener('click', () => { confirmModal.style.display = 'none'; });
    if (btnCancelConfirmTop) btnCancelConfirmTop.addEventListener('click', () => { confirmModal.style.display = 'none'; });
    if (btnProceedPublish) {
        btnProceedPublish.addEventListener('click', () => {
            confirmModal.style.display = 'none';
            executePublish();
        });
    }

    async function executePublish() {
        btnPublishPost.disabled = true;
        btnPublishPost.innerHTML = '<span>Publishing Live...</span>';

        try {
            const res = await fetch('api/article.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
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
                })
            });

            const data = await res.json();

            if (res.ok && data.success) {
                currentPublishedData = data.article;
                currentDraftData = null;

                let successMsg = 'Article published live successfully! newsletter.greenavic.org is updated.';
                if (data.email_delivery) {
                    if (data.email_delivery.attempted) {
                        successMsg += ` Broadcast sent: ${data.email_delivery.sent_count} delivered (${data.email_delivery.failed_count} failed) to ${data.email_delivery.total_subscribers} subscribers.`;
                    } else if (data.email_delivery.reason) {
                        successMsg += ` (Email Note: ${data.email_delivery.reason})`;
                    }
                }
                showToast(successMsg);
                closeEditor();
                renderOverview();
                if (typeof loadSubscriberAudienceSummary === 'function') {
                    loadSubscriberAudienceSummary();
                }
            } else {
                showToast(data.error || 'Failed to publish article. Existing publication preserved.', true);
            }
        } catch (error) {
            showToast('Network error while publishing.', true);
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

    // ----------------------------------------------------
    // Team & Administrators Modal Handling
    // ----------------------------------------------------
    function applyAdminCounts(data) {
        if (!data) return;
        const total = data.count || (Array.isArray(data.admins) ? data.admins.length : 0);
        const adminCount = data.admin_count !== undefined ? data.admin_count : total;
        const modCount = data.moderator_count || 0;
        const editorCount = data.editor_count || 0;

        if (navAdminsCount) navAdminsCount.textContent = total;
        if (navAdminsCountMobile) navAdminsCountMobile.textContent = total;
        if (modalAdminsCount) modalAdminsCount.textContent = total;
        if (overviewAdminBadgeCount) {
            if (modCount > 0 || editorCount > 0) {
                overviewAdminBadgeCount.textContent = `${total} (${adminCount} Admin${adminCount !== 1 ? 's' : ''}, ${modCount} Mod${modCount !== 1 ? 's' : ''}${editorCount > 0 ? `, ${editorCount} Editor${editorCount !== 1 ? 's' : ''}` : ''})`;
            } else {
                overviewAdminBadgeCount.textContent = `${adminCount} Admin${adminCount !== 1 ? 's' : ''}`;
            }
        }
    }

    async function loadAdminCountSummary() {
        try {
            const res = await fetch('api/auth.php?action=list_admins', { 
                cache: 'no-cache',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            if (!res.ok) return;
            const data = await res.json();
            if (data && (Array.isArray(data.admins) || data.count !== undefined)) {
                applyAdminCounts(data);
            }
        } catch (e) {
            // Silently ignore summary error
        }
    }

    if (btnManageAdmins) {
        btnManageAdmins.addEventListener('click', (e) => {
            e.preventDefault();
            openModal(adminsModal);
            loadAdminsList();
        });
    }

    if (overviewAdminBadge) {
        overviewAdminBadge.addEventListener('click', (e) => {
            e.preventDefault();
            openModal(adminsModal);
            loadAdminsList();
        });
        overviewAdminBadge.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                overviewAdminBadge.click();
            }
        });
    }

    if (btnRefreshAdmins) {
        btnRefreshAdmins.addEventListener('click', () => loadAdminsList());
    }

    // Generate client invite link
    if (btnGenerateInvite) {
        btnGenerateInvite.addEventListener('click', async () => {
            btnGenerateInvite.disabled = true;
            btnGenerateInvite.textContent = 'Generating...';
            try {
                const res = await fetch('api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'create_invite', csrf_token: csrfToken })
                });
                const data = await res.json();
                if (res.ok && data.success) {
                    inviteLinkInput.value = data.invite_url;
                    if (btnCopyInviteLink) btnCopyInviteLink.style.display = 'inline-block';
                    showToast('Client invite link created! Click Copy to share.');
                } else {
                    showToast(data.error || 'Failed to generate invite token.', true);
                }
            } catch (e) {
                showToast('Connection error while generating invite.', true);
            } finally {
                btnGenerateInvite.disabled = false;
                btnGenerateInvite.textContent = 'Generate New Link';
            }
        });
    }

    // Copy invite link
    if (btnCopyInviteLink) {
        btnCopyInviteLink.addEventListener('click', () => {
            inviteLinkInput.select();
            navigator.clipboard.writeText(inviteLinkInput.value).then(() => {
                btnCopyInviteLink.textContent = 'Copied!';
                setTimeout(() => { btnCopyInviteLink.textContent = 'Copy'; }, 2000);
                showToast('Invite link copied to clipboard!');
            });
        });
    }

    // Add admin directly
    if (formAddAdminDirect) {
        formAddAdminDirect.addEventListener('submit', async (e) => {
            e.preventDefault();
            const nameEl = document.getElementById('newAdminName');
            const roleEl = document.getElementById('newAdminRole');
            const name = nameEl ? nameEl.value.trim() : '';
            const role = roleEl ? roleEl.value : 'admin';
            const email = document.getElementById('newAdminEmail').value.trim();
            const password = document.getElementById('newAdminPassword').value;

            try {
                const res = await fetch('api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'add_admin', name, role, email, password, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (res.ok && data.success) {
                    showToast('New administrator added successfully!');
                    formAddAdminDirect.reset();
                    loadAdminsList();
                } else {
                    showToast(data.error || 'Failed to add administrator.', true);
                }
            } catch (e) {
                showToast('Network error while adding administrator.', true);
            }
        });
    }

    async function loadAdminsList() {
        if (!adminsListContainer) return;
        adminsListContainer.innerHTML = '<p style="font-size: 13px; color: #888; padding: 16px;">Loading active administrators...</p>';

        try {
            const res = await fetch('api/auth.php?action=list_admins', { 
                cache: 'no-cache',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });

            if (!res.ok) {
                const errData = await res.json().catch(() => ({}));
                adminsListContainer.innerHTML = `
                    <div style="padding: 16px; color: #C62828; text-align: center;">
                        <p style="font-size: 13px; margin-bottom: 8px;">${escapeHtml(errData.error || 'Failed to load administrators (HTTP ' + res.status + ')')}</p>
                        <button type="button" class="btn btn-outline btn-sm" onclick="loadAdminsList()">Retry</button>
                    </div>`;
                return;
            }

            const data = await res.json();
            if (data && Array.isArray(data.admins)) {
                applyAdminCounts(data);

                const count = data.admins.length;
                if (count === 0) {
                    adminsListContainer.innerHTML = '<p style="font-size: 13px; color: #888; padding: 16px; text-align: center;">No administrators found.</p>';
                    return;
                }

                let html = `
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                        <thead style="background: #F8FAF9; border-bottom: 1px solid #EAEAEA;">
                            <tr>
                                <th style="padding: 10px 14px; color: #555;">Name &amp; Email</th>
                                <th style="padding: 10px 14px; color: #555;">Role</th>
                                <th style="padding: 10px 14px; color: #555;">Status</th>
                                <th style="padding: 10px 14px; color: #555;">Joined</th>
                                <th style="padding: 10px 14px; color: #555; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                data.admins.forEach(admin => {
                    const isCurrent = admin.is_current;
                    const joined = admin.created_at ? formatDate(admin.created_at) : 'Active';
                    const isPending = (admin.status === 'Pending Setup');
                    const statusBadgeStyle = isPending
                        ? 'background: #FFF8E1; color: #F57F17; border: 1px solid #FFE082;'
                        : 'background: #E8F5E9; color: #2E7D32; border: 1px solid #C8E6C9;';

                    // Role badge styling
                    const r = (admin.role || 'admin').toLowerCase();
                    let roleLabel = 'Administrator';
                    let roleBadgeStyle = 'background: #EBF3FA; color: #1565C0; border: 1px solid #BBDEFB;';
                    if (r === 'moderator') {
                        roleLabel = 'Moderator';
                        roleBadgeStyle = 'background: #FFF3E0; color: #E65100; border: 1px solid #FFE0B2;';
                    } else if (r === 'editor') {
                        roleLabel = 'Editor';
                        roleBadgeStyle = 'background: #F3E5F5; color: #6A1B9A; border: 1px solid #E1BEE7;';
                    }
                    
                    html += `
                        <tr style="border-bottom: 1px solid #F0F0F0;">
                            <td style="padding: 12px 14px;">
                                <div style="font-weight: 600; color: #1B382B;">${escapeHtml(admin.name || 'Administrator')}</div>
                                <div style="font-size: 12px; color: #666;">${escapeHtml(admin.email)}</div>
                            </td>
                            <td style="padding: 12px 14px;">
                                <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; padding: 2px 7px; border-radius: 4px; display: inline-block; ${roleBadgeStyle}">
                                    ${escapeHtml(roleLabel)}
                                </span>
                            </td>
                            <td style="padding: 12px 14px;">
                                <span style="font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px; display: inline-block; ${statusBadgeStyle}">
                                    ${escapeHtml(admin.status || 'Active')}
                                </span>
                            </td>
                            <td style="padding: 12px 14px; color: #666; font-size: 12px;">
                                ${escapeHtml(joined)}
                            </td>
                            <td style="padding: 12px 14px; text-align: right;">
                                ${isCurrent ? `
                                    <span style="font-size: 11px; background: #E8F5E9; color: #2E7D32; padding: 3px 8px; border-radius: 4px; font-weight: 600;">You</span>
                                ` : `
                                    <button type="button" class="btn btn-outline" onclick="deleteAdminUser(${admin.id}, '${escapeHtml(admin.email)}')" style="padding: 4px 10px; font-size: 11px; color: #C62828; border-color: #FFCDD2;">Remove</button>
                                `}
                            </td>
                        </tr>
                    `;
                });
                html += `</tbody></table>`;
                adminsListContainer.innerHTML = html;
            } else {
                adminsListContainer.innerHTML = '<p style="font-size: 13px; color: #C62828; padding: 16px;">Failed to load administrators.</p>';
            }
        } catch (e) {
            console.error('Admins load error:', e);
            adminsListContainer.innerHTML = `
                <div style="padding: 16px; color: #C62828; text-align: center;">
                    <p style="font-size: 13px; margin-bottom: 8px;">Network error loading administrators.</p>
                    <button type="button" class="btn btn-outline btn-sm" onclick="loadAdminsList()">Retry</button>
                </div>`;
        }
    }

    window.loadAdminsList = loadAdminsList;

    window.deleteAdminUser = async function(userId, email) {
        if (!confirm(`Are you sure you want to remove administrator "${email}"? They will lose dashboard access.`)) return;
        try {
            const res = await fetch('api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: 'delete_admin', user_id: userId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (res.ok && data.success) {
                showToast(`Administrator "${email}" removed.`);
                loadAdminsList();
            } else {
                showToast(data.error || 'Failed to remove administrator.', true);
            }
        } catch (e) {
            showToast('Network error while removing admin.', true);
        }
    };

    // ----------------------------------------------------
    // Subscribers & Broadcasts Management
    // ----------------------------------------------------

    async function loadSubscriberAudienceSummary() {
        try {
            const res = await fetch('api/subscribers.php', { cache: 'no-cache' });
            if (!res.ok) return;
            const data = await res.json();
            if (data.stats) {
                if (overviewSubscribersCount) {
                    overviewSubscribersCount.textContent = data.stats.active || 0;
                }
                if (mobileAudienceCountBadge) {
                    mobileAudienceCountBadge.textContent = `${data.stats.active || 0} Active`;
                }
            }
        } catch (e) {
            // Silently ignore summary fetch error
        }
    }

    async function loadSubscribersData() {
        if (!subscribersTableBody) return;
        subscribersTableBody.innerHTML = '<tr><td colspan="4" style="padding: 24px; text-align: center; color: #888;">Loading subscriber records...</td></tr>';

        try {
            const res = await fetch('api/subscribers.php', { cache: 'no-cache' });
            if (!res.ok) {
                subscribersTableBody.innerHTML = '<tr><td colspan="4" style="padding: 24px; text-align: center; color: #C62828;">Failed to load subscribers.</td></tr>';
                return;
            }

            const data = await res.json();
            if (data.csrf_token) {
                csrfToken = data.csrf_token;
            }

            // 1. Stats Cards
            if (data.stats) {
                if (statActiveSubscribers) statActiveSubscribers.textContent = data.stats.active || 0;
                if (statPendingSubscribers) statPendingSubscribers.textContent = data.stats.total || data.stats.active || 0;
                if (statUnsubscribed) statUnsubscribed.textContent = data.stats.unsubscribed || 0;
                if (overviewSubscribersCount) overviewSubscribersCount.textContent = data.stats.active || 0;
                if (mobileAudienceCountBadge) mobileAudienceCountBadge.textContent = `${data.stats.active || 0} Active`;
            }

            // 2. Rolling 24-Hour Quota
            if (data.quota) {
                if (statQuotaUsed) statQuotaUsed.textContent = `${data.quota.rolling_24h_used} / ${data.quota.rolling_24h_limit} used`;
                if (statQuotaAvail) statQuotaAvail.textContent = `${data.quota.available_quota} available`;
            }

            // 3. Cron Job Status & Helper
            if (data.cron) {
                if (cronStatusPill) {
                    if (data.cron.last_run) {
                        cronStatusPill.style.background = '#E8F5E9';
                        cronStatusPill.style.color = '#2E7D32';
                        cronStatusPill.textContent = `Active (${formatDate(data.cron.last_run)})`;
                    } else {
                        cronStatusPill.style.background = '#FFF3E0';
                        cronStatusPill.style.color = '#E65100';
                        cronStatusPill.textContent = 'Awaiting first run';
                    }
                }
                if (cronCommandText && data.cron.secret) {
                    cronCommandText.textContent = `wget -q -O - "https://admin.greenavic.org/api/cron.php?secret=${data.cron.secret}" >/dev/null 2>&1`;
                }
            }

            // 4. SMTP Status
            if (data.smtp) {
                if (statSmtpStatus) {
                    statSmtpStatus.innerHTML = data.smtp.configured 
                        ? '<span style="color:#2E7D32;">● Connected</span>' 
                        : '<span style="color:#D32F2F;">● Not Configured</span>';
                }
                if (statSmtpDesc) {
                    statSmtpDesc.textContent = data.smtp.configured 
                        ? `${data.smtp.user || data.smtp.from_email} via ${data.smtp.host}` 
                        : 'Credentials missing in cms-core/.env';
                }
            }

            // 5. Latest Campaign Card
            if (data.latest_campaign) {
                const c = data.latest_campaign;
                const isSent = c.status === 'sent';
                const isSending = c.status === 'sending';
                const isProcessing = c.status === 'processing';
                const badgeClass = isSent ? 'published' : ((isSending || isProcessing) ? 'draft' : 'empty');
                
                if (campaignStatusBadge) {
                    campaignStatusBadge.className = 'status-badge ' + badgeClass;
                    campaignStatusBadge.textContent = c.status.toUpperCase();
                }

                const pendingDeliveries = Math.max(0, (parseInt(c.total_subscribers, 10) || 0) - (parseInt(c.sent_count, 10) || 0) - (parseInt(c.failed_count, 10) || 0));

                let detailsHtml = `
                    <div style="margin-bottom: 6px;"><strong>Article:</strong> "${escapeHtml(c.subject)}"</div>
                    <div><strong>Broadcast Performance:</strong> ${c.sent_count} sent &bull; ${c.failed_count} failed &bull; ${pendingDeliveries} queued (out of ${c.total_subscribers} total)</div>
                    <div style="font-size: 11.5px; color: #888; margin-top: 4px;">Created on ${formatDate(c.created_at)}</div>
                `;

                if (btnProcessBatch) {
                    if (pendingDeliveries > 0 || isSending || isProcessing) {
                        btnProcessBatch.style.display = 'inline-block';
                        btnProcessBatch.textContent = `Process Next Batch (${pendingDeliveries} queued)`;
                    } else {
                        btnProcessBatch.style.display = 'none';
                    }
                }

                if (btnRetryCampaign) {
                    if (c.failed_count > 0) {
                        btnRetryCampaign.style.display = 'inline-block';
                        btnRetryCampaign.dataset.campaignId = c.id;
                        btnRetryCampaign.textContent = `Retry ${c.failed_count} Failed Deliveries`;
                    } else {
                        btnRetryCampaign.style.display = 'none';
                    }
                }

                if (c.failed_count > 0 && data.failed_deliveries && data.failed_deliveries.length > 0) {
                    detailsHtml += `
                        <div style="margin-top: 10px; padding: 10px; background: #FFF4F4; border-radius: 6px; font-size: 12px; color: #C62828;">
                            <strong>Delivery Errors:</strong>
                            <ul style="margin: 6px 0 0 16px; padding: 0;">
                                ${data.failed_deliveries.slice(0, 5).map(f => `<li>${escapeHtml(f.email)}: ${escapeHtml(f.error_message || 'Connection error')}</li>`).join('')}
                            </ul>
                        </div>
                    `;
                }

                if (campaignDetails) campaignDetails.innerHTML = detailsHtml;
            } else {
                if (campaignStatusBadge) {
                    campaignStatusBadge.className = 'status-badge empty';
                    campaignStatusBadge.textContent = 'NO BROADCASTS YET';
                }
                if (campaignDetails) {
                    campaignDetails.innerHTML = '<p style="margin: 0; color: #777;">When you publish an article, it will automatically broadcast to all active subscribers.</p>';
                }
                if (btnProcessBatch) btnProcessBatch.style.display = 'none';
                if (btnRetryCampaign) btnRetryCampaign.style.display = 'none';
            }

            // 6. Subscribers Table
            if (!data.subscribers || data.subscribers.length === 0) {
                subscribersTableBody.innerHTML = '<tr><td colspan="4" style="padding: 24px; text-align: center; color: #888;">No subscribers yet. Audience will appear here as users subscribe on newsletter.greenavic.org.</td></tr>';
                return;
            }

            let rows = '';
            data.subscribers.forEach(sub => {
                const isActive = sub.status === 'active';
                const isPending = sub.status === 'pending';
                let statusBadge = '';
                if (isActive) {
                    statusBadge = '<span style="display:inline-block; font-size:11px; font-weight:700; background:#E8F5E9; color:#2E7D32; padding:2px 8px; border-radius:12px;">ACTIVE</span>';
                } else if (isPending) {
                    statusBadge = '<span style="display:inline-block; font-size:11px; font-weight:700; background:#E3F2FD; color:#1565C0; padding:2px 8px; border-radius:12px;">PENDING CONFIRMATION</span>';
                } else {
                    statusBadge = '<span style="display:inline-block; font-size:11px; font-weight:700; background:#FFF3E0; color:#E65100; padding:2px 8px; border-radius:12px;">UNSUBSCRIBED</span>';
                }
                
                const subDate = (sub.confirmed_at || sub.created_at) ? new Date(sub.confirmed_at || sub.created_at).toLocaleDateString() : '';

                rows += `
                    <tr style="border-bottom: 1px solid #EAEAEA;">
                        <td style="padding: 10px 14px; font-weight: 600; color: #1B382B;">${escapeHtml(sub.email)}</td>
                        <td style="padding: 10px 14px;">${statusBadge}</td>
                        <td style="padding: 10px 14px; color: #666; font-size: 12px;">${subDate}</td>
                        <td style="padding: 10px 14px; text-align: right;">
                            <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                ${isActive ? `
                                    <button type="button" class="sub-action-btn suppress" onclick="changeSubscriberStatus(${sub.id}, 'suppress')">Suppress</button>
                                ` : (isPending ? `
                                    <button type="button" class="sub-action-btn activate" onclick="changeSubscriberStatus(${sub.id}, 'activate')">Confirm</button>
                                ` : `
                                    <button type="button" class="sub-action-btn activate" onclick="changeSubscriberStatus(${sub.id}, 'activate')">Reactivate</button>
                                `)}
                                <button type="button" class="sub-action-btn delete" onclick="deleteSubscriber(${sub.id}, '${escapeHtml(sub.email)}')">Delete</button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            subscribersTableBody.innerHTML = rows;

        } catch (e) {
            console.error('Subscribers load error:', e);
            subscribersTableBody.innerHTML = '<tr><td colspan="4" style="padding: 24px; text-align: center; color: #C62828;">Network error loading subscribers.</td></tr>';
        }
    }

    if (btnManageSubscribers) {
        btnManageSubscribers.addEventListener('click', (e) => {
            e.preventDefault();
            openModal(subscribersModal);
            loadSubscribersData();
        });
    }

    if (overviewAudienceBadge) {
        overviewAudienceBadge.addEventListener('click', (e) => {
            e.preventDefault();
            openModal(subscribersModal);
            loadSubscribersData();
        });
        overviewAudienceBadge.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                overviewAudienceBadge.click();
            }
        });
    }

    // Suppress or Reactivate
    window.changeSubscriberStatus = async function(id, action) {
        try {
            const res = await fetch('api/subscribers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: action, id: id, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (res.ok && data.success) {
                showToast(data.message || 'Subscriber status updated.');
                loadSubscribersData();
            } else {
                showToast(data.error || 'Failed to update subscriber.', true);
            }
        } catch (e) {
            showToast('Network error while updating subscriber.', true);
        }
    };

    // Delete Subscriber
    window.deleteSubscriber = async function(id, email) {
        if (!confirm(`Are you sure you want to permanently delete subscriber "${email}"?`)) return;
        try {
            const res = await fetch('api/subscribers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: 'delete', id: id, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (res.ok && data.success) {
                showToast(`Subscriber "${email}" deleted.`);
                loadSubscribersData();
            } else {
                showToast(data.error || 'Failed to delete subscriber.', true);
            }
        } catch (e) {
            showToast('Network error while deleting subscriber.', true);
        }
    };

    // Process Batch Manually
    if (btnProcessBatch) {
        btnProcessBatch.addEventListener('click', async () => {
            btnProcessBatch.disabled = true;
            btnProcessBatch.textContent = 'Processing Queue...';

            try {
                const res = await fetch('api/subscribers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'process_batch', csrf_token: csrfToken })
                });
                const data = await res.json();
                if (res.ok && data.success) {
                    const r = data.result || {};
                    showToast(`Batch processed: ${r.sent || 0} sent, ${r.failed || 0} failed (${r.remaining || 0} remaining in queue).`);
                    loadSubscribersData();
                } else {
                    showToast(data.error || 'Failed to process queue batch.', true);
                }
            } catch (e) {
                showToast('Network error while processing queue.', true);
            } finally {
                btnProcessBatch.disabled = false;
            }
        });
    }

    // Retry Campaign Deliveries
    if (btnRetryCampaign) {
        btnRetryCampaign.addEventListener('click', async () => {
            const campId = btnRetryCampaign.dataset.campaignId;
            if (!campId) return;

            btnRetryCampaign.disabled = true;
            btnRetryCampaign.textContent = 'Retrying Deliveries...';

            try {
                const res = await fetch('api/subscribers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'retry_campaign', campaign_id: parseInt(campId, 10), csrf_token: csrfToken })
                });
                const data = await res.json();
                if (res.ok && data.success) {
                    showToast(data.message || 'Retry completed successfully.');
                    loadSubscribersData();
                } else {
                    showToast(data.error || 'Failed to retry deliveries.', true);
                }
            } catch (e) {
                showToast('Network error during delivery retry.', true);
            } finally {
                btnRetryCampaign.disabled = false;
            }
        });
    }

    // Test SMTP
    if (btnTestSmtp) {
        btnTestSmtp.addEventListener('click', async () => {
            const adminEmail = (adminEmailDisplay ? adminEmailDisplay.textContent.trim() : '');
            const target = prompt('Enter the email address to receive the test email:', adminEmail);
            if (!target) return;

            btnTestSmtp.disabled = true;
            btnTestSmtp.textContent = 'Sending Test...';

            try {
                const res = await fetch('api/subscribers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'test_smtp', email: target, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (res.ok && data.success) {
                    showToast(data.message || 'Test email sent successfully!');
                } else {
                    showToast(data.error || 'SMTP test failed.', true);
                }
            } catch (e) {
                showToast('Network error while testing SMTP.', true);
            } finally {
                btnTestSmtp.disabled = false;
                btnTestSmtp.textContent = 'Test SMTP';
            }
        });
    }

    // Add Subscriber Manually
    if (formAddSubscriberDirect && newSubEmail) {
        formAddSubscriberDirect.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = newSubEmail.value.trim();
            if (!email) return;

            try {
                const res = await fetch('api/subscribers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'add', email: email, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (res.ok && data.success) {
                    showToast(data.message || 'Subscriber added successfully.');
                    newSubEmail.value = '';
                    loadSubscribersData();
                } else {
                    showToast(data.error || 'Failed to add subscriber.', true);
                }
            } catch (e) {
                showToast('Network error adding subscriber.', true);
            }
        });
    }

    // ----------------------------------------------------
    // System Health & Diagnostic Logs Modal Handling
    // ----------------------------------------------------
    if (btnHealthLogs && healthLogsModal) {
        btnHealthLogs.addEventListener('click', () => {
            openModal(healthLogsModal);
            loadSystemHealth();
            loadSystemLogs();
        });
    }

    if (btnRepairDatabase) {
        btnRepairDatabase.addEventListener('click', async () => {
            const originalText = btnRepairDatabase.textContent;
            btnRepairDatabase.disabled = true;
            btnRepairDatabase.textContent = 'Verifying & Repairing...';
            try {
                const res = await fetch('api/health.php?action=repair', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin'
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.success) {
                    showToast(data.message || 'Database schema and images verified successfully.', false);
                    loadSystemHealth();
                } else {
                    showToast(data.error || 'Failed to repair database.', true);
                }
            } catch (err) {
                console.error('Repair error:', err);
                showToast('Network error running database repair.', true);
            } finally {
                btnRepairDatabase.disabled = false;
                btnRepairDatabase.textContent = originalText;
            }
        });
    }

    if (tabBtnHealth && tabBtnLogs && tabContentHealth && tabContentLogs) {
        tabBtnHealth.addEventListener('click', () => {
            tabBtnHealth.style.borderBottomColor = '#2E7D32';
            tabBtnHealth.style.color = '#2E7D32';
            tabBtnLogs.style.borderBottomColor = 'transparent';
            tabBtnLogs.style.color = '#666';
            tabContentHealth.style.display = 'block';
            tabContentLogs.style.display = 'none';
        });

        tabBtnLogs.addEventListener('click', () => {
            tabBtnLogs.style.borderBottomColor = '#2E7D32';
            tabBtnLogs.style.color = '#2E7D32';
            tabBtnHealth.style.borderBottomColor = 'transparent';
            tabBtnHealth.style.color = '#666';
            tabContentLogs.style.display = 'block';
            tabContentHealth.style.display = 'none';
            loadSystemLogs();
        });
    }

    if (btnRefreshHealth) {
        btnRefreshHealth.addEventListener('click', () => loadSystemHealth());
    }

    if (btnFilterLogs) {
        btnFilterLogs.addEventListener('click', () => loadSystemLogs());
    }

    if (logLevelFilter) {
        logLevelFilter.addEventListener('change', () => loadSystemLogs());
    }

    if (logSearchInput) {
        logSearchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                loadSystemLogs();
            }
        });
    }

    async function loadSystemHealth() {
        if (!healthDbBadge) return;
        healthDbBadge.textContent = 'Checking...';
        healthSmtpBadge.textContent = 'Checking...';

        try {
            const res = await fetch('api/health.php', {
                cache: 'no-cache',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });

            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                healthDbBadge.textContent = 'Degraded';
                healthDbBadge.className = 'status-badge empty';
                healthSmtpBadge.textContent = 'Unavailable';
                healthSmtpBadge.className = 'status-badge empty';
                return;
            }

            const data = await res.json();
            // Database
            if (data.database && data.database.connected) {
                healthDbBadge.textContent = 'Operational';
                healthDbBadge.className = 'status-badge published';
                if (healthDbName) healthDbName.textContent = data.database.name || '-';
                if (healthDbLatency) healthDbLatency.textContent = data.database.latency_ms || '< 1';
                if (healthDbSchema) healthDbSchema.textContent = 'v2.1';
            } else {
                healthDbBadge.textContent = 'Offline';
                healthDbBadge.className = 'status-badge empty';
            }

            // SMTP Gateway
            if (data.smtp) {
                const conf = data.smtp.configured;
                const tested = data.smtp.tested;
                const verified = data.smtp.delivery_verified;

                if (healthSmtpConfigured) {
                    healthSmtpConfigured.textContent = conf ? 'Yes (smtp.hostinger.com)' : 'Missing Password';
                    healthSmtpConfigured.style.color = conf ? '#2E7D32' : '#C62828';
                }
                if (healthSmtpTested) {
                    healthSmtpTested.textContent = tested ? 'Socket Connected (Port 465 SSL)' : (conf ? 'Failed (' + (data.smtp.test_error || 'Timeout') + ')' : 'Not Configured');
                    healthSmtpTested.style.color = tested ? '#2E7D32' : '#C62828';
                }
                if (healthSmtpVerified) {
                    healthSmtpVerified.textContent = verified ? 'Verified (' + data.smtp.total_sent_emails + ' delivered)' : 'No live emails delivered yet';
                    healthSmtpVerified.style.color = verified ? '#2E7D32' : '#E65100';
                }

                if (conf && tested) {
                    healthSmtpBadge.textContent = verified ? 'Verified & Active' : 'Ready to Send';
                    healthSmtpBadge.className = 'status-badge published';
                } else {
                    healthSmtpBadge.textContent = 'Attention Needed';
                    healthSmtpBadge.className = 'status-badge empty';
                }
            }

            // Queue
            if (data.queue) {
                if (healthActiveSubs) healthActiveSubs.textContent = data.queue.active_subscribers || 0;
                if (healthPendingDeliv) healthPendingDeliv.textContent = data.queue.pending_deliveries || 0;
                if (healthFailedDeliv) healthFailedDeliv.textContent = data.queue.failed_deliveries || 0;
            }

            // Server runtime
            if (data.server) {
                if (healthRuntimeBadge) healthRuntimeBadge.textContent = 'PHP ' + (data.server.php_version ? data.server.php_version.split('-')[0] : '8.3');
                if (healthMemory) healthMemory.textContent = data.server.memory_usage + ' / ' + data.server.memory_limit;
                if (healthDisk) healthDisk.textContent = data.server.disk.free_gb + ' GB free';
                if (healthServerTime) healthServerTime.textContent = data.server.server_time || '-';
            }
        } catch (e) {
            console.error('Health diagnostics error:', e);
            if (healthDbBadge) {
                healthDbBadge.textContent = 'Connection Error';
                healthDbBadge.className = 'status-badge empty';
            }
        }
    }

    async function loadSystemLogs() {
        if (!logsTableBody) return;
        logsTableBody.innerHTML = '<tr><td colspan="5" style="padding: 20px; text-align: center; color: #888;">Loading logs...</td></tr>';

        const level = logLevelFilter ? logLevelFilter.value : 'all';
        const search = logSearchInput ? encodeURIComponent(logSearchInput.value.trim()) : '';

        try {
            const url = `api/logs.php?action=logs&level=${level}&search=${search}&limit=50`;
            const res = await fetch(url, {
                cache: 'no-cache',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });

            if (!res.ok) {
                logsTableBody.innerHTML = '<tr><td colspan="5" style="padding: 20px; text-align: center; color: #C62828;">Failed to load system logs.</td></tr>';
                return;
            }

            const data = await res.json();
            if (data && Array.isArray(data.logs)) {
                if (data.logs.length === 0) {
                    logsTableBody.innerHTML = '<tr><td colspan="5" style="padding: 20px; text-align: center; color: #888;">No log entries found matching criteria.</td></tr>';
                    return;
                }

                let html = '';
                data.logs.forEach(log => {
                    const lvl = (log.level || 'info').toLowerCase();
                    let lvlBadge = '<span style="font-size: 10px; font-weight: 700; background: #E8F5E9; color: #2E7D32; padding: 2px 6px; border-radius: 4px;">INFO</span>';
                    if (lvl === 'error') {
                        lvlBadge = '<span style="font-size: 10px; font-weight: 700; background: #FFEBEE; color: #C62828; padding: 2px 6px; border-radius: 4px;">ERROR</span>';
                    } else if (lvl === 'warning') {
                        lvlBadge = '<span style="font-size: 10px; font-weight: 700; background: #FFF8E1; color: #E65100; padding: 2px 6px; border-radius: 4px;">WARN</span>';
                    }

                    html += `
                        <tr style="border-bottom: 1px solid #F0F0F0;">
                            <td style="padding: 8px 10px; color: #777; white-space: nowrap;">${escapeHtml(log.created_at)}</td>
                            <td style="padding: 8px 10px;">${lvlBadge}</td>
                            <td style="padding: 8px 10px; font-weight: 600; color: #1B382B;">${escapeHtml(log.channel || 'app')}</td>
                            <td style="padding: 8px 10px; color: #333; word-break: break-word;">${escapeHtml(log.message)}</td>
                            <td style="padding: 8px 10px; color: #888; font-family: monospace;">${escapeHtml(log.ip_address || '-')}</td>
                        </tr>
                    `;
                });
                logsTableBody.innerHTML = html;
            } else {
                logsTableBody.innerHTML = '<tr><td colspan="5" style="padding: 20px; text-align: center; color: #C62828;">Invalid logs response received.</td></tr>';
            }
        } catch (e) {
            console.error('Error loading logs:', e);
            logsTableBody.innerHTML = '<tr><td colspan="5" style="padding: 20px; text-align: center; color: #C62828;">Network error fetching system logs.</td></tr>';
        }
    }

    window.addEventListener('click', (e) => {
        if (e.target === previewModal) closeModal(previewModal);
        if (e.target === confirmModal) closeModal(confirmModal);
        if (adminsModal && e.target === adminsModal) closeModal(adminsModal);
        if (subscribersModal && e.target === subscribersModal) closeModal(subscribersModal);
        if (healthLogsModal && e.target === healthLogsModal) closeModal(healthLogsModal);
        if (passwordChangeModal && e.target === passwordChangeModal) closeModal(passwordChangeModal);
    });

    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (previewModal && previewModal.style.display !== 'none') closeModal(previewModal);
            if (confirmModal && confirmModal.style.display !== 'none') closeModal(confirmModal);
            if (adminsModal && adminsModal.style.display !== 'none') closeModal(adminsModal);
            if (subscribersModal && subscribersModal.style.display !== 'none') closeModal(subscribersModal);
            if (healthLogsModal && healthLogsModal.style.display !== 'none') closeModal(healthLogsModal);
            if (passwordChangeModal && passwordChangeModal.style.display !== 'none') closeModal(passwordChangeModal);
        }
    });

    verifyAuth();
});
