/**
 * Green AVIC - Main JavaScript
 * Clean and Simple
 */

// ==========================================
// ANIMATED COUNTER FOR STATS
// ==========================================
function animateCounter(element, target, duration = 2000) {
    let start = 0;
    const increment = target / (duration / 16); // 60fps
    const isPercentage = element.textContent.includes('%') || element.parentElement.querySelector('.stat-label');

    function updateCounter() {
        start += increment;

        if (start < target) {
            element.textContent = Math.floor(start) + (isPercentage ? '%' : '');
            requestAnimationFrame(updateCounter);
        } else {
            element.textContent = target + (isPercentage ? '%' : '');
        }
    }

    updateCounter();
}

// Initialize counters when they come into view
function initCounters() {
    const counters = document.querySelectorAll('.stat-number[data-target]');
    let hasAnimated = false;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !hasAnimated) {
                hasAnimated = true;

                // Animate all counters
                counters.forEach(counter => {
                    const target = parseInt(counter.getAttribute('data-target'));
                    animateCounter(counter, target);
                });
            }
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -100px 0px'
    });

    // Observe the hero section
    const heroSection = document.querySelector('.hero');
    if (heroSection) {
        observer.observe(heroSection);
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', initCounters);

// Mobile Menu Toggle
const navToggle = document.getElementById('navToggle');
const navList = document.querySelector('.nav-list');

if (navToggle) {
    navToggle.addEventListener('click', () => {
        navList.classList.toggle('active');
        navToggle.classList.toggle('active');
    });

    // Close menu when clicking on a link
    document.querySelectorAll('.nav-list a').forEach(link => {
        link.addEventListener('click', () => {
            navList.classList.remove('active');
            navToggle.classList.remove('active');
        });
    });

    // Close menu when clicking outside
    document.addEventListener('click', (e) => {
        if (!navToggle.contains(e.target) && !navList.contains(e.target)) {
            navList.classList.remove('active');
            navToggle.classList.remove('active');
        }
    });
}

// Smooth Scroll
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (href === '#' || href === '') return;

        e.preventDefault();
        const target = document.querySelector(href);
        if (target) {
            const offset = 70;
            const targetPosition = target.offsetTop - offset;
            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
        }
    });
});

// Newsletter Form
const newsletterForm = document.querySelector('.newsletter-form');
if (newsletterForm) {
    newsletterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const email = newsletterForm.querySelector('input[type="email"]').value;
        alert(`Thank you for subscribing with ${email}! We'll keep you updated.`);
        newsletterForm.reset();
    });
}

// Add scroll effect to header
const header = document.querySelector('.header');
let lastScroll = 0;

window.addEventListener('scroll', () => {
    const currentScroll = window.pageYOffset;

    if (currentScroll > 100) {
        header.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    } else {
        header.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
    }

    lastScroll = currentScroll;
});

// ==========================================
// SCROLL ANIMATIONS - Elements slide in!
// ==========================================
const scrollObserverOptions = {
    threshold: 0.15,
    rootMargin: '0px 0px -50px 0px'
};

const scrollObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('revealed');
        }
    });
}, scrollObserverOptions);

// Observe all animated elements
document.querySelectorAll('.scroll-reveal, .scroll-left, .scroll-right').forEach(element => {
    scrollObserver.observe(element);
});

// Observe feature cards and product cards
document.querySelectorAll('.feature-card, .product-card').forEach(card => {
    card.classList.add('scroll-reveal');
    scrollObserver.observe(card);
});

// Observe grid items
document.querySelectorAll('.grid-item').forEach((item, index) => {
    item.classList.add('scroll-reveal');
    item.style.transitionDelay = `${index * 0.1}s`;
    scrollObserver.observe(item);
});

// ==========================================
// IMPACT CAROUSEL - Auto-sliding with manual controls
// ==========================================
class ImpactCarousel {
    constructor() {
        this.track = document.querySelector('.carousel-track');
        this.slides = document.querySelectorAll('.carousel-slide');
        this.prevBtn = document.querySelector('.carousel-btn-prev');
        this.nextBtn = document.querySelector('.carousel-btn-next');
        this.dots = document.querySelectorAll('.dot');

        if (!this.track) return; // Exit if carousel doesn't exist

        this.currentSlide = 0;
        this.totalSlides = this.slides.length;
        this.autoSlideInterval = null;
        this.autoSlideDelay = 5000; // 5 seconds

        this.init();
    }

    init() {
        // Button event listeners
        this.prevBtn.addEventListener('click', () => this.goToPrevSlide());
        this.nextBtn.addEventListener('click', () => this.goToNextSlide());

        // Dot event listeners
        this.dots.forEach((dot, index) => {
            dot.addEventListener('click', () => this.goToSlide(index));
        });

        // Touch/Swipe support for mobile
        let touchStartX = 0;
        let touchEndX = 0;

        this.track.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
        });

        this.track.addEventListener('touchend', (e) => {
            touchEndX = e.changedTouches[0].screenX;
            this.handleSwipe(touchStartX, touchEndX);
        });

        // Pause auto-slide on hover
        const container = document.querySelector('.carousel-container');
        container.addEventListener('mouseenter', () => this.pauseAutoSlide());
        container.addEventListener('mouseleave', () => this.startAutoSlide());

        // Start auto-sliding
        this.startAutoSlide();
    }

    goToSlide(slideIndex) {
        // Ensure index is within bounds
        if (slideIndex < 0) {
            this.currentSlide = this.totalSlides - 1;
        } else if (slideIndex >= this.totalSlides) {
            this.currentSlide = 0;
        } else {
            this.currentSlide = slideIndex;
        }

        // Move the track
        const offset = -this.currentSlide * 100;
        this.track.style.transform = `translateX(${offset}%)`;

        // Update dots
        this.updateDots();
    }

    goToNextSlide() {
        this.goToSlide(this.currentSlide + 1);
        this.resetAutoSlide();
    }

    goToPrevSlide() {
        this.goToSlide(this.currentSlide - 1);
        this.resetAutoSlide();
    }

    updateDots() {
        this.dots.forEach((dot, index) => {
            if (index === this.currentSlide) {
                dot.classList.add('active');
            } else {
                dot.classList.remove('active');
            }
        });
    }

    handleSwipe(startX, endX) {
        const swipeThreshold = 50;
        const diff = startX - endX;

        if (Math.abs(diff) > swipeThreshold) {
            if (diff > 0) {
                // Swiped left - go to next
                this.goToNextSlide();
            } else {
                // Swiped right - go to previous
                this.goToPrevSlide();
            }
        }
    }

    startAutoSlide() {
        this.autoSlideInterval = setInterval(() => {
            this.goToNextSlide();
        }, this.autoSlideDelay);
    }

    pauseAutoSlide() {
        if (this.autoSlideInterval) {
            clearInterval(this.autoSlideInterval);
        }
    }

    resetAutoSlide() {
        this.pauseAutoSlide();
        this.startAutoSlide();
    }
}

// Initialize carousel when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new ImpactCarousel();
});

console.log('%c Green AVIC ', 'background: #4CAF50; color: white; padding: 10px 20px; font-size: 14px; font-weight: bold;');
console.log('%c Redefining Hygiene, Elevating Women ', 'color: #4CAF50; font-size: 12px;');
