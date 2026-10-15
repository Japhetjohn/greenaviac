/**
 * Green AVIC - Public Blog & Insights Client
 * Fetches latest single publication, manages reader mode and SEO schema
 */

document.addEventListener('DOMContentLoaded', () => {
    const loadingEl = document.getElementById('blogLoading');
    const emptyEl = document.getElementById('blogEmpty');
    const errorEl = document.getElementById('blogError');
    const featuredCard = document.getElementById('blogFeatured');
    const readerView = document.getElementById('blogReader');

    const btnReadArticle = document.getElementById('btnReadArticle');
    const btnBackToOverview = document.getElementById('btnBackToOverview');
    const btnBackToOverviewBottom = document.getElementById('btnBackToOverviewBottom');

    const btnShareTwitter = document.getElementById('btnShareTwitter');
    const btnShareLinkedIn = document.getElementById('btnShareLinkedIn');
    const btnCopyLink = document.getElementById('btnCopyLink');
    const copyToast = document.getElementById('copyToast');

    let currentArticle = null;

    // Helper: Format ISO date string into readable format (e.g. "October 13, 2026")
    function formatDate(isoString) {
        if (!isoString) return '';
        try {
            const date = new Date(isoString);
            if (isNaN(date.getTime())) return isoString;
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        } catch (e) {
            return isoString;
        }
    }

    // Helper: Show toast notification
    function showToast(message) {
        if (!copyToast) return;
        copyToast.textContent = message;
        copyToast.classList.add('show');
        setTimeout(() => {
            copyToast.classList.remove('show');
        }, 3000);
    }

    // Switch between Featured overview and Full Reader mode
    function showReaderMode(animate = true) {
        if (!currentArticle) return;
        featuredCard.style.display = 'none';
        readerView.style.display = 'block';

        if (window.location.hash !== '#read') {
            history.pushState(null, '', '#read');
        }

        if (animate) {
            readerView.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function showOverviewMode(animate = true) {
        readerView.style.display = 'none';
        featuredCard.style.display = 'grid';

        if (window.location.hash === '#read') {
            history.pushState(null, '', window.location.pathname);
        }

        if (animate) {
            featuredCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    // Update SEO Meta Tags & JSON-LD schema
    function updateSeoMetadata(article) {
        document.title = `${article.title} | Green AVIC`;

        // Update meta description
        const metaDesc = document.querySelector('meta[name="description"]');
        if (metaDesc && article.excerpt) {
            metaDesc.setAttribute('content', article.excerpt);
        }

        // Update Open Graph tags
        const ogTitle = document.getElementById('ogTitle');
        const ogDesc = document.getElementById('ogDesc');
        const ogImage = document.getElementById('ogImage');
        if (ogTitle) ogTitle.setAttribute('content', article.title);
        if (ogDesc && article.excerpt) ogDesc.setAttribute('content', article.excerpt);

        // Update Twitter tags
        const twTitle = document.getElementById('twTitle');
        const twDesc = document.getElementById('twDesc');
        const twImage = document.getElementById('twImage');
        if (twTitle) twTitle.setAttribute('content', article.title);
        if (twDesc && article.excerpt) twDesc.setAttribute('content', article.excerpt);

        if (article.cover_image) {
            const absoluteImageUrl = new URL(article.cover_image, window.location.origin).href;
            if (ogImage) ogImage.setAttribute('content', absoluteImageUrl);
            if (twImage) twImage.setAttribute('content', absoluteImageUrl);
        }

        // Update Structured Data JSON-LD
        const schemaEl = document.getElementById('articleSchema');
        if (schemaEl) {
            const schemaData = {
                "@context": "https://schema.org",
                "@type": "BlogPosting",
                "headline": article.title,
                "description": article.excerpt,
                "url": window.location.href,
                "datePublished": article.published_at || article.updated_at,
                "dateModified": article.updated_at || article.published_at,
                "author": {
                    "@type": "Organization",
                    "name": article.author || "Green AVIC",
                    "url": "https://greenavic.org"
                },
                "publisher": {
                    "@type": "Organization",
                    "name": "Green AVIC",
                    "logo": {
                        "@type": "ImageObject",
                        "url": "https://greenavic.org/assets/images/brand/greenavic-logo-real.png"
                    }
                }
            };

            if (article.cover_image) {
                schemaData.image = new URL(article.cover_image, window.location.origin).href;
            }

            schemaEl.textContent = JSON.stringify(schemaData, null, 2);
        }
    }

    // Render Article Data onto DOM
    function renderArticle(article) {
        currentArticle = article;

        function decodeEntities(str) {
            if (!str) return '';
            const t = document.createElement('textarea');
            t.innerHTML = str;
            return t.value;
        }

        const formattedDate = formatDate(article.published_at || article.updated_at);
        const readTimeStr = `${article.reading_time || 3} min read`;
        const authorName = decodeEntities(article.author || 'Green AVIC');
        const categoryName = decodeEntities(article.category || 'Sustainability');
        const cleanTitle = decodeEntities(article.title || '');
        const cleanExcerpt = decodeEntities(article.excerpt || '');

        // 1. Featured Overview Card
        const featTitle = document.getElementById('featuredTitle');
        const featExcerpt = document.getElementById('featuredExcerpt');
        const featCategory = document.getElementById('featuredCategory');
        const featDate = document.getElementById('featuredDate');
        const featReadTime = document.getElementById('featuredReadTime');
        const featAuthor = document.getElementById('featuredAuthor');
        const featImage = document.getElementById('featuredImage');
        const featMediaContainer = document.getElementById('featuredMediaContainer');

        if (featTitle) featTitle.textContent = cleanTitle;
        if (featExcerpt) featExcerpt.textContent = cleanExcerpt;
        if (featCategory) featCategory.textContent = categoryName;
        if (featDate) {
            featDate.textContent = formattedDate;
            featDate.setAttribute('datetime', article.published_at || '');
        }
        if (featReadTime) featReadTime.textContent = readTimeStr;
        if (featAuthor) featAuthor.textContent = authorName;

        if (featImage && featMediaContainer) {
            const fallbackPlaceholder = 'assets/images/brand/greenavic-placeholder.svg';
            featImage.onerror = function() {
                this.onerror = null;
                this.src = fallbackPlaceholder;
            };
            if (article.cover_image) {
                featImage.src = article.cover_image;
                featImage.alt = article.cover_alt || article.title;
                featMediaContainer.style.display = 'block';
            } else {
                featImage.src = fallbackPlaceholder;
                featImage.alt = 'Green AVIC Publication';
                featMediaContainer.style.display = 'block';
            }
        }

        // 2. Full Article Reader Mode View
        const readTitle = document.getElementById('readerTitle');
        const readExcerpt = document.getElementById('readerExcerpt');
        const readCategory = document.getElementById('readerCategory');
        const readDate = document.getElementById('readerDate');
        const readTime = document.getElementById('readerReadTime');
        const readAuthor = document.getElementById('readerAuthor');
        const readBody = document.getElementById('readerBody');
        const readFigure = document.getElementById('readerFigure');
        const readImage = document.getElementById('readerImage');
        const readCaption = document.getElementById('readerCaption');

        if (readTitle) readTitle.textContent = cleanTitle;
        if (readExcerpt) readExcerpt.textContent = cleanExcerpt;
        if (readCategory) readCategory.textContent = categoryName;
        if (readDate) {
            readDate.textContent = formattedDate;
            readDate.setAttribute('datetime', article.published_at || '');
        }
        if (readTime) readTime.textContent = readTimeStr;
        if (readAuthor) readAuthor.textContent = authorName;
        if (readBody) readBody.innerHTML = article.body;

        if (readFigure && readImage) {
            const fallbackPlaceholder = 'assets/images/brand/greenavic-placeholder.svg';
            readImage.onerror = function() {
                this.onerror = null;
                this.src = fallbackPlaceholder;
            };
            if (article.cover_image) {
                readImage.src = article.cover_image;
                readImage.alt = article.cover_alt || article.title;
                if (readCaption) {
                    readCaption.textContent = article.cover_alt ? article.cover_alt : article.title;
                }
                readFigure.style.display = 'block';
            } else {
                readFigure.style.display = 'none';
            }
        }

        // Update SEO
        updateSeoMetadata(article);

        // Hide loading and show appropriate view
        loadingEl.style.display = 'none';

        if (window.location.hash === '#read') {
            showReaderMode(false);
        } else {
            showOverviewMode(false);
        }
    }

    // Fetch Article from API
    async function loadArticle() {
        try {
            // First try api/article.php, then api/article fallback
            let response;
            try {
                response = await fetch('api/article.php', { cache: 'no-cache' });
            } catch (err) {
                response = await fetch('api/article', { cache: 'no-cache' });
            }

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data && data.article && data.article.status === 'published') {
                renderArticle(data.article);
            } else {
                // Polished empty state
                loadingEl.style.display = 'none';
                emptyEl.style.display = 'block';
            }
        } catch (error) {
            console.error('Error fetching article:', error);
            loadingEl.style.display = 'none';
            errorEl.style.display = 'block';
        }
    }

    // Event Listeners for Reader Navigation
    if (btnReadArticle) {
        btnReadArticle.addEventListener('click', () => showReaderMode(true));
    }

    if (btnBackToOverview) {
        btnBackToOverview.addEventListener('click', () => showOverviewMode(true));
    }

    if (btnBackToOverviewBottom) {
        btnBackToOverviewBottom.addEventListener('click', () => showOverviewMode(true));
    }

    // Listen to Browser Back / Forward buttons for hash changes
    window.addEventListener('popstate', () => {
        if (window.location.hash === '#read') {
            if (currentArticle) showReaderMode(false);
        } else {
            if (currentArticle) showOverviewMode(false);
        }
    });

    // Social Sharing handlers
    if (btnShareTwitter) {
        btnShareTwitter.addEventListener('click', () => {
            const shareUrl = encodeURIComponent(window.location.href);
            const shareTitle = encodeURIComponent(currentArticle ? currentArticle.title : document.title);
            window.open(`https://twitter.com/intent/tweet?text=${shareTitle}&url=${shareUrl}`, '_blank', 'width=600,height=400');
        });
    }

    if (btnShareLinkedIn) {
        btnShareLinkedIn.addEventListener('click', () => {
            const shareUrl = encodeURIComponent(window.location.href);
            window.open(`https://www.linkedin.com/sharing/share-offsite/?url=${shareUrl}`, '_blank', 'width=600,height=500');
        });
    }

    if (btnCopyLink) {
        btnCopyLink.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(window.location.href);
                showToast('Article link copied to clipboard!');
            } catch (err) {
                // Fallback for older browsers
                const input = document.createElement('input');
                input.value = window.location.href;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                showToast('Article link copied to clipboard!');
            }
        });
    }

    // Newsletter Subscription Form Handler
    const subscribeForm = document.getElementById('subscribeForm');
    const subscriberEmail = document.getElementById('subscriberEmail');
    const btnSubscribe = document.getElementById('btnSubscribe');
    const subscribeFeedback = document.getElementById('subscribeFeedback');
    const subscriberConsent = document.getElementById('subscriberConsent');
    const hpWebsite = document.getElementById('hp_website');

    if (subscribeForm && subscriberEmail && btnSubscribe) {
        subscribeForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const email = (subscriberEmail.value || '').trim();
            const hpVal = hpWebsite ? hpWebsite.value : '';

            // Client-side quick email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!email || !emailRegex.test(email)) {
                if (subscribeFeedback) {
                    subscribeFeedback.className = 'subscribe-feedback is-error';
                    subscribeFeedback.textContent = 'Please enter a valid email address.';
                }
                subscriberEmail.focus();
                return;
            }

            // Consent validation
            if (subscriberConsent && !subscriberConsent.checked) {
                if (subscribeFeedback) {
                    subscribeFeedback.className = 'subscribe-feedback is-error';
                    subscribeFeedback.textContent = 'Please check the box to agree to receive newsletter updates.';
                }
                subscriberConsent.focus();
                return;
            }

            // UI Loading state
            btnSubscribe.disabled = true;
            btnSubscribe.classList.add('is-loading');
            if (subscribeFeedback) {
                subscribeFeedback.className = 'subscribe-feedback';
                subscribeFeedback.textContent = '';
            }

            try {
                const response = await fetch('api/subscribe', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        email: email,
                        consent: true,
                        website_url: hpVal
                    })
                });

                const result = await response.json().catch(() => ({}));

                if (response.ok && result.success) {
                    if (subscribeFeedback) {
                        subscribeFeedback.className = 'subscribe-feedback is-success';
                        subscribeFeedback.textContent = result.message || 'Thank you for subscribing to Green AVIC News & Insights!';
                    }
                    subscriberEmail.value = '';
                } else {
                    if (subscribeFeedback) {
                        subscribeFeedback.className = 'subscribe-feedback is-error';
                        subscribeFeedback.textContent = result.error || 'Unable to complete subscription. Please try again.';
                    }
                }
            } catch (err) {
                if (subscribeFeedback) {
                    subscribeFeedback.className = 'subscribe-feedback is-error';
                    subscribeFeedback.textContent = 'Network error. Please check your connection and try again.';
                }
            } finally {
                btnSubscribe.disabled = false;
                btnSubscribe.classList.remove('is-loading');
            }
        });
    }

    // Initial Load
    loadArticle();
});

