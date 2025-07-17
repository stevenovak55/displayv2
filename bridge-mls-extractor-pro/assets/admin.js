/**
 * Bridge MLS Extractor Pro - Admin JavaScript
 */

(function($) {
    'use strict';
    
    const BME = {
        
        /**
         * Initialize admin functionality
         */
        init: function() {
            this.bindEvents();
            this.initComponents();
            this.loadAsyncStats();
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Confirmation dialogs
            $(document).on('click', '.bme-confirm-clear', this.confirmClear);
            $(document).on('click', '.bme-confirm-resync', this.confirmResync);
            
            // Filter interactions
            $(document).on('change', '.bme-filter-select', this.handleFilterChange);
            $(document).on('click', '#bme-advanced-filters', this.toggleAdvancedFilters);
            
            // AJAX actions
            $(document).on('click', '.bme-load-stats', this.loadExtractionStats);
            $(document).on('submit', '#bme-listings-filter', this.handleFilterSubmit);
            
            // Real-time updates
            this.setupRealTimeUpdates();
        },
        
        /**
         * Initialize components
         */
        initComponents: function() {
            // Initialize Select2 dropdowns
            if ($.fn.select2) {
                $('.bme-filter-select').select2({
                    allowClear: true,
                    placeholder: function() {
                        return $(this).data('placeholder') || 'Select...';
                    }
                });
            }
            
            // Initialize tooltips
            if ($.fn.tooltip) {
                $('[data-tooltip]').tooltip();
            }
            
            // Initialize date pickers
            if ($.fn.datepicker) {
                $('.bme-date-picker').datepicker({
                    dateFormat: 'yy-mm-dd'
                });
            }
        },
        
        /**
         * Load asynchronous statistics
         */
        loadAsyncStats: function() {
            $('.bme-loading[data-extraction-id]').each(function() {
                const $element = $(this);
                const extractionId = $element.data('extraction-id');
                
                BME.loadExtractionStatsById(extractionId, function(stats) {
                    if (stats && stats.total_listings !== undefined) {
                        // Use .text() to prevent XSS when inserting dynamic data
                        $element.text(BME.numberFormat(stats.total_listings));
                        $element.removeClass('bme-loading');
                    }
                });
            });
        },
        
        /**
         * Setup real-time updates for running extractions
         */
        setupRealTimeUpdates: function() {
            // Check for running extractions every 30 seconds
            setInterval(function() {
                BME.checkRunningExtractions();
            }, 30000);
        },
        
        /**
         * Confirm clear data action
         */
        confirmClear: function(e) {
            if (!confirm(bmeAdmin.strings.confirmClear)) {
                e.preventDefault();
                return false;
            }
        },
        
        /**
         * Confirm resync action
         */
        confirmResync: function(e) {
            if (!confirm(bmeAdmin.strings.confirmResync)) {
                e.preventDefault();
                return false;
            }
        },
        
        /**
         * Handle filter changes
         */
        handleFilterChange: function() {
            const $form = $(this).closest('form');
            
            // Auto-submit if configured
            if ($form.data('auto-submit')) {
                $form.submit();
            }
        },
        
        /**
         * Toggle advanced filters
         */
        toggleAdvancedFilters: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const $panel = $('#bme-advanced-panel');
            
            $panel.slideToggle(function() {
                const isVisible = $panel.is(':visible');
                $button.text(isVisible ? 'Hide Advanced' : 'Advanced Filters');
            });
        },
        
        /**
         * Handle filter form submission
         */
        handleFilterSubmit: function(e) {
            const $form = $(this);
            const $submitBtn = $form.find('[type="submit"]');
            
            // Show loading state
            $submitBtn.prop('disabled', true).text(bmeAdmin.strings.loading);
            
            // Allow normal form submission
            // Loading state will be reset on page load
        },
        
        /**
         * Load extraction statistics
         */
        loadExtractionStats: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const extractionId = $button.data('extraction-id');
            
            BME.loadExtractionStatsById(extractionId, function(stats) {
                BME.displayStatsModal(stats);
            });
        },
        
        /**
         * Load extraction statistics by ID
         */
        loadExtractionStatsById: function(extractionId, callback) {
            $.post(bmeAdmin.ajaxUrl, {
                action: 'bme_get_extraction_stats',
                extraction_id: extractionId,
                nonce: bmeAdmin.nonce
            }, function(response) {
                if (response.success) {
                    callback(response.data);
                } else {
                    console.error('Failed to load stats:', response.data);
                    BME.showNotification(bmeAdmin.strings.error, 'error');
                }
            }).fail(function() {
                console.error('AJAX request failed');
                BME.showNotification(bmeAdmin.strings.error, 'error');
            });
        },
        
        /**
         * Display statistics modal
         */
        displayStatsModal: function(stats) {
            // Create modal HTML
            const modalHtml = BME.buildStatsModalHtml(stats);
            
            // Show modal (using WordPress admin styles)
            const $modal = $(modalHtml).appendTo('body');
            $modal.show();
            
            // Bind close events
            $modal.on('click', '.bme-modal-close, .bme-modal-overlay', function() {
                $modal.fadeOut(function() {
                    $modal.remove();
                });
            });
            
            // Prevent modal content clicks from closing
            $modal.on('click', '.bme-modal-content', function(e) {
                e.stopPropagation();
            });
        },
        
        /**
         * Build statistics modal HTML
         * Note: For security, ensure all dynamic content is properly escaped.
         * For numeric values, BME.numberFormat already handles them.
         * For string values like dates, BME.formatDate is used.
         * If any raw string data from the API were to be displayed, it should be escaped.
         */
        buildStatsModalHtml: function(stats) {
            // Helper to safely escape HTML for string values
            const escapeHtml = (text) => {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.replace(/[&<>"']/g, function(m) { return map[m]; });
            };

            return `
                <div class="bme-modal-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; display: flex; align-items: center; justify-content: center;">
                    <div class="bme-modal-content" style="background: white; padding: 20px; border-radius: 4px; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto;">
                        <div class="bme-modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                            <h2 style="margin: 0;">Extraction Statistics</h2>
                            <button class="bme-modal-close" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                        </div>
                        <div class="bme-modal-body">
                            <div class="bme-stats-grid">
                                <div class="bme-stat-item">
                                    <div class="bme-stat-value">${BME.numberFormat(stats.total_listings || 0)}</div>
                                    <div class="bme-stat-label">Total Listings</div>
                                </div>
                                <div class="bme-stat-item">
                                    <div class="bme-stat-value">${BME.numberFormat(stats.unique_statuses || 0)}</div>
                                    <div class="bme-stat-label">Unique Statuses</div>
                                </div>
                                <div class="bme-stat-item">
                                    <div class="bme-stat-value">$${BME.numberFormat(stats.avg_price || 0)}</div>
                                    <div class="bme-stat-label">Average Price</div>
                                </div>
                                <div class="bme-stat-item">
                                    <div class="bme-stat-value">$${BME.numberFormat(stats.min_price || 0)}</div>
                                    <div class="bme-stat-label">Min Price</div>
                                </div>
                                <div class="bme-stat-item">
                                    <div class="bme-stat-value">$${BME.numberFormat(stats.max_price || 0)}</div>
                                    <div class="bme-stat-label">Max Price</div>
                                </div>
                            </div>
                            ${stats.oldest_listing ? `
                                <p><strong>Oldest Listing:</strong> ${escapeHtml(BME.formatDate(stats.oldest_listing))}</p>
                            ` : ''}
                            ${stats.newest_update ? `
                                <p><strong>Latest Update:</strong> ${escapeHtml(BME.formatDate(stats.newest_update))}</p>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        },
        
        /**
         * Check for running extractions
         */
        checkRunningExtractions: function() {
            // This would typically check for extraction status updates
            // For now, we'll just update timestamps
            $('.bme-last-run small').each(function() {
                const $element = $(this);
                const text = $element.text();
                
                // Update relative timestamps
                if (text.includes('ago')) {
                    // Would need server data to properly update this
                    // For a true real-time update, an AJAX call to get fresh data
                    // for each relevant extraction would be needed here.
                    // Example: BME.loadExtractionStatsById(extractionId, function(stats) { /* update UI */ });
                }
            });
        },
        
        /**
         * Search listings with AJAX
         */
        searchListings: function(filters, page) {
            return $.post(bmeAdmin.ajaxUrl, {
                action: 'bme_search_listings',
                filters: filters,
                page: page || 1,
                nonce: bmeAdmin.nonce
            });
        },
        
        /**
         * Get filter values with AJAX
         */
        getFilterValues: function(field) {
            return $.post(bmeAdmin.ajaxUrl, {
                action: 'bme_get_filter_values',
                field: field,
                nonce: bmeAdmin.nonce
            });
        },
        
        /**
         * Utility function to format numbers
         */
        numberFormat: function(number) {
            return new Intl.NumberFormat().format(number);
        },
        
        /**
         * Utility function to format dates
         */
        formatDate: function(dateString) {
            const date = new Date(dateString);
            // Ensure date is valid before formatting
            if (isNaN(date.getTime())) {
                return 'Invalid Date';
            }
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        },
        
        /**
         * Show notification
         */
        showNotification: function(message, type) {
            type = type || 'info';
            
            const $notice = $(`
                <div class="notice notice-${type} is-dismissible bme-notice">
                    <p>${message}</p>
                </div>
            `);
            
            $('.wrap h1').after($notice);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $notice.remove();
                });
            }, 5000);
        },
        
        /**
         * Show loading overlay
         */
        showLoading: function($element) {
            $element.addClass('bme-loading-overlay');
        },
        
        /**
         * Hide loading overlay
         */
        hideLoading: function($element) {
            $element.removeClass('bme-loading-overlay');
        },
        
        /**
         * Debounce function for search inputs
         */
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = function() {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        BME.init();
    });
    
    // Make BME object globally available
    window.BME = BME;
    
})(jQuery);
