/**
 * JavaScript for the Single Property Details Page.
 * Handles the photo gallery and sticky sidebar.
 */
document.addEventListener('DOMContentLoaded', function() {
    // --- Gallery Script ---
    if (typeof mldSinglePropertyData !== 'undefined' && mldSinglePropertyData.photos) {
        const photos = mldSinglePropertyData.photos;
        if (photos && photos.length > 1) {
            let currentIndex = 0;
            const mainPhoto = document.getElementById('mld-main-photo');
            const thumbnailsContainer = document.querySelector('.mld-gallery-thumbnails');
            const allThumbs = document.querySelectorAll('.mld-thumb');

            const updateMainPhoto = (index) => {
                if (!photos[index]) return;
                mainPhoto.style.opacity = 0;
                setTimeout(() => {
                    mainPhoto.src = photos[index];
                    mainPhoto.style.opacity = 1;
                }, 200);
                
                currentIndex = index;
                allThumbs.forEach(thumb => thumb.classList.remove('active'));
                const activeThumb = document.querySelector(`.mld-thumb[data-index="${index}"]`);
                if(activeThumb) {
                    activeThumb.classList.add('active');
                    activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                }
            };

            document.querySelector('.mld-slider-nav.next').addEventListener('click', () => updateMainPhoto((currentIndex + 1) % photos.length));
            document.querySelector('.mld-slider-nav.prev').addEventListener('click', () => updateMainPhoto((currentIndex - 1 + photos.length) % photos.length));
            thumbnailsContainer.addEventListener('click', e => {
                if (e.target.classList.contains('mld-thumb')) {
                    updateMainPhoto(parseInt(e.target.dataset.index, 10));
                }
            });
        }
    }

    // --- Sticky Sidebar Script ---
    const sidebar = document.querySelector('.mld-sidebar-sticky-content');
    if (sidebar) {
        const headerOffset = document.body.classList.contains('admin-bar') ? 32 : 0;
        sidebar.style.top = (headerOffset + 20) + 'px'; // 20px margin from top
    }
});
