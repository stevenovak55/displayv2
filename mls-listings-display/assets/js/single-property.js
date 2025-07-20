/**
 * JavaScript for the Single Property Details Page.
 * v7.1.0
 * - FEAT: Added robust full-width gallery logic for mobile.
 * - REFACTOR: Implemented a highly reliable, JS-driven sticky sidebar using a placeholder method.
 * - FIX: Corrected sticky sidebar logic to prevent disappearing sidebar issue.
 */
document.addEventListener('DOMContentLoaded', function() {
    
    const gallery = document.querySelector('.mld-gallery');

    // --- Full-width Gallery on Mobile ---
    function handleGalleryWidth() {
        const container = document.querySelector('#mld-single-property-page .mld-container');
        const gallery = document.querySelector('.mld-gallery');
        const pageWrapper = document.querySelector('#mld-single-property-page');
        
        if (window.innerWidth <= 1140) {
            // Remove all constraints from page wrapper
            if (pageWrapper) {
                pageWrapper.style.padding = '0';
                pageWrapper.style.margin = '0';
                pageWrapper.style.maxWidth = '100%';
                pageWrapper.style.width = '100%';
            }
            
            // Make container full width
            if (container) {
                container.style.maxWidth = '100%';
                container.style.padding = '0';
                container.style.margin = '0';
                container.style.width = '100%';
            }
            
            // Make gallery truly full width
            if (gallery) {
                const viewportWidth = window.innerWidth;
                gallery.style.width = viewportWidth + 'px';
                gallery.style.maxWidth = viewportWidth + 'px';
                gallery.style.margin = '0';
                gallery.style.padding = '0';
                gallery.style.position = 'relative';
                
                // Calculate the offset needed to center the gallery
                const galleryRect = gallery.getBoundingClientRect();
                const offsetLeft = galleryRect.left;
                gallery.style.marginLeft = `-${offsetLeft}px`;
                gallery.style.marginRight = `-${offsetLeft}px`;
            }
        } else {
            // Reset to desktop styles
            if (pageWrapper) {
                pageWrapper.style.padding = '';
                pageWrapper.style.margin = '';
                pageWrapper.style.maxWidth = '';
                pageWrapper.style.width = '';
            }
            
            if (container) {
                container.style.maxWidth = '1200px';
                container.style.padding = '20px';
                container.style.margin = '0 auto';
                container.style.width = '';
            }
            
            if (gallery) {
                gallery.style.width = '';
                gallery.style.maxWidth = '';
                gallery.style.margin = '';
                gallery.style.padding = '';
                gallery.style.position = '';
                gallery.style.marginLeft = '';
                gallery.style.marginRight = '';
            }
        }
    }

    // --- Swipeable Gallery Script ---
    if (gallery) {
        const slider = gallery.querySelector('.mld-gallery-slider');
        const slides = gallery.querySelectorAll('.mld-gallery-slide');
        const prevButton = gallery.querySelector('.mld-slider-nav.prev');
        const nextButton = gallery.querySelector('.mld-slider-nav.next');
        const thumbnails = gallery.querySelectorAll('.mld-thumb');

        if (slider && slides.length > 1) {
            let currentIndex = 0;
            let isDragging = false;
            let startPos = 0;
            let currentTranslate = 0;
            let prevTranslate = 0;
            let animationID;

            const goToSlide = (index) => {
                if (index < 0 || index >= slides.length) return;
                const slideWidth = slides[0].offsetWidth;
                slider.style.transition = 'transform 0.3s ease-out';
                currentTranslate = index * -slideWidth;
                slider.style.transform = `translateX(${currentTranslate}px)`;
                prevTranslate = currentTranslate;
                currentIndex = index;
                updateUI();
            };

            const updateUI = () => {
                thumbnails.forEach((thumb, index) => {
                    thumb.classList.toggle('active', index === currentIndex);
                    if(index === currentIndex) {
                        thumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    }
                });
                prevButton.style.display = currentIndex === 0 ? 'none' : 'flex';
                nextButton.style.display = currentIndex === slides.length - 1 ? 'none' : 'flex';
            };

            const getPositionX = (event) => event.type.includes('mouse') ? event.pageX : event.touches[0].clientX;

            const dragStart = (event) => {
                isDragging = true;
                startPos = getPositionX(event);
                slider.style.transition = 'none';
                animationID = requestAnimationFrame(animation);
                gallery.querySelector('.mld-gallery-main-image').classList.add('is-dragging');
            };

            const drag = (event) => {
                if (isDragging) {
                    const currentPosition = getPositionX(event);
                    currentTranslate = prevTranslate + currentPosition - startPos;
                }
            };

            const dragEnd = () => {
                if (!isDragging) return;
                isDragging = false;
                cancelAnimationFrame(animationID);
                const movedBy = currentTranslate - prevTranslate;
                if (movedBy < -100 && currentIndex < slides.length - 1) currentIndex++;
                if (movedBy > 100 && currentIndex > 0) currentIndex--;
                goToSlide(currentIndex);
                gallery.querySelector('.mld-gallery-main-image').classList.remove('is-dragging');
            };

            const animation = () => {
                slider.style.transform = `translateX(${currentTranslate}px)`;
                if (isDragging) requestAnimationFrame(animation);
            };

            slider.addEventListener('mousedown', dragStart);
            slider.addEventListener('touchstart', dragStart, { passive: true });
            slider.addEventListener('mouseup', dragEnd);
            slider.addEventListener('mouseleave', dragEnd);
            slider.addEventListener('touchend', dragEnd);
            slider.addEventListener('mousemove', drag);
            slider.addEventListener('touchmove', drag, { passive: true });
            prevButton.addEventListener('click', () => goToSlide(currentIndex - 1));
            nextButton.addEventListener('click', () => goToSlide(currentIndex + 1));
            thumbnails.forEach((thumb, index) => thumb.addEventListener('click', () => goToSlide(index)));
            
            window.addEventListener('resize', () => {
                handleGalleryWidth();
                goToSlide(currentIndex);
            });

            handleGalleryWidth(); // Initial check
            updateUI();
        }
    }

    // --- Simple Sticky Sidebar Script ---
    const sidebar = document.querySelector('.mld-sidebar');
    if (sidebar && window.matchMedia('(min-width: 992px)').matches) {
        const mainContent = document.querySelector('.mld-listing-details');
        const baseTopSpacing = 20;
        
        // Store original position info
        let originalOffsetTop = null;
        let originalParent = sidebar.parentElement;
        let sidebarWidth = null;
        let sidebarLeft = null;
        let dynamicTopOffset = baseTopSpacing;

        const calculateTopOffset = () => {
            let maxHeaderHeight = 0;
            
            // Check for common fixed header elements
            const potentialHeaders = document.querySelectorAll('header, .header, .site-header, .main-header, #header, #masthead, .navbar, .nav-bar, .top-bar');
            
            potentialHeaders.forEach(el => {
                const style = window.getComputedStyle(el);
                if (style.position === 'fixed' || style.position === 'sticky') {
                    const rect = el.getBoundingClientRect();
                    if (rect.top <= 10 && rect.height > 0) { // Element is at or near the top
                        maxHeaderHeight = Math.max(maxHeaderHeight, rect.bottom);
                    }
                }
            });
            
            // Also check for WordPress admin bar
            const adminBar = document.querySelector('#wpadminbar');
            if (adminBar) {
                const adminBarStyle = window.getComputedStyle(adminBar);
                if (adminBarStyle.position === 'fixed') {
                    const adminBarRect = adminBar.getBoundingClientRect();
                    if (adminBarRect.top <= 10) {
                        maxHeaderHeight = Math.max(maxHeaderHeight, adminBarRect.bottom);
                    }
                }
            }
            
            // Set minimum offset and add base spacing
            dynamicTopOffset = Math.max(maxHeaderHeight, 0) + baseTopSpacing;
            
            // Fallback: if no headers detected but screen seems to have typical header space
            if (maxHeaderHeight === 0) {
                // Check if there's any content at the very top that might be a header
                const topElements = document.elementsFromPoint(window.innerWidth / 2, 50);
                const hasLikelyHeader = topElements.some(el => {
                    const tagName = el.tagName.toLowerCase();
                    const className = el.className.toLowerCase();
                    return tagName.includes('header') || 
                           className.includes('header') || 
                           className.includes('navbar') ||
                           className.includes('nav-bar') ||
                           tagName === 'nav';
                });
                
                if (hasLikelyHeader) {
                    dynamicTopOffset = 80; // Conservative estimate for typical header height
                }
            }
        };

        const initializeSidebar = () => {
            calculateTopOffset();
            
            // Get original position
            const sidebarRect = sidebar.getBoundingClientRect();
            const parentRect = originalParent.getBoundingClientRect();
            
            originalOffsetTop = sidebar.offsetTop;
            sidebarWidth = sidebar.offsetWidth;
            sidebarLeft = sidebarRect.left;
        };

        const handleScroll = () => {
            if (!mainContent || originalOffsetTop === null) return;
            
            const scrollTop = window.pageYOffset;
            const mainContentBottom = mainContent.offsetTop + mainContent.offsetHeight;
            const sidebarHeight = sidebar.offsetHeight;
            
            // Calculate when sidebar should stick
            const stickyPoint = originalOffsetTop - dynamicTopOffset;
            
            if (scrollTop >= stickyPoint) {
                // Calculate bottom boundary
                const sidebarBottom = scrollTop + sidebarHeight + dynamicTopOffset;
                let topPosition = dynamicTopOffset;
                
                // If sidebar would go past main content, adjust position
                if (sidebarBottom > mainContentBottom) {
                    topPosition = mainContentBottom - scrollTop - sidebarHeight;
                    topPosition = Math.max(topPosition, -(sidebarHeight - window.innerHeight + dynamicTopOffset));
                }
                
                // Apply sticky styles
                sidebar.style.position = 'fixed';
                sidebar.style.top = topPosition + 'px';
                sidebar.style.left = sidebarLeft + 'px';
                sidebar.style.width = sidebarWidth + 'px';
                sidebar.style.zIndex = '100';
                sidebar.classList.add('is-sticky');
            } else {
                // Remove sticky styles
                sidebar.style.position = '';
                sidebar.style.top = '';
                sidebar.style.left = '';
                sidebar.style.width = '';
                sidebar.style.zIndex = '';
                sidebar.classList.remove('is-sticky');
            }
        };

        const handleResize = () => {
            // Reset and recalculate on resize
            sidebar.style.position = '';
            sidebar.style.top = '';
            sidebar.style.left = '';
            sidebar.style.width = '';
            sidebar.style.zIndex = '';
            sidebar.classList.remove('is-sticky');
            
            setTimeout(() => {
                initializeSidebar();
                handleScroll();
            }, 100);
        };

        // Initialize after elements are rendered
        setTimeout(() => {
            initializeSidebar();
            handleScroll();
            
            window.addEventListener('scroll', handleScroll);
            window.addEventListener('resize', handleResize);
            
            // Recalculate if new elements are added (like admin bars)
            const observer = new MutationObserver(() => {
                calculateTopOffset();
            });
            observer.observe(document.body, { childList: true, subtree: false });
        }, 300);
    }
});