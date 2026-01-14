/**
 * PDF Viewer JavaScript
 * Integrates PDF.js for document rendering and annotation support
 * 
 * @package IAdS
 * @subpackage PDF Annotation System
 */

// PDF.js Worker Setup
if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

class PDFViewer {
    constructor(options = {}) {
        this.pdfUrl = options.pdfUrl || '';
        this.containerId = options.containerId || 'pdf-canvas-container';
        this.currentPage = 1;
        this.totalPages = 0;
        this.pdfDoc = null;
        this.scale = options.scale || 1.5;
        this.canvas = null;
        this.ctx = null;
        this.isLoading = false;
        this.annotations = [];
        this.onAnnotationClick = options.onAnnotationClick || null;
        
        this.init();
    }
    
    /**
     * Initialize PDF viewer
     */
    async init() {
        if (!this.pdfUrl) {
            console.error('PDF URL is required');
            return;
        }
        
        try {
            this.isLoading = true;
            this.showLoadingState();
            
            // Load PDF document
            this.pdfDoc = await pdfjsLib.getDocument(this.pdfUrl).promise;
            this.totalPages = this.pdfDoc.numPages;
            
            // Create canvas
            this.createCanvas();
            
            // Render first page
            await this.renderPage(1);
            
            // Update page info
            this.updatePageInfo();
            
            this.isLoading = false;
            this.hideLoadingState();
        } catch (error) {
            console.error('Error loading PDF:', error);
            this.showError('Failed to load PDF document');
        }
    }
    
    /**
     * Create canvas element
     */
    createCanvas() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            console.error('Container not found');
            return;
        }
        
        // Clear container
        container.innerHTML = '';
        
        // Create wrapper for canvas to handle positioning
        const canvasWrapper = document.createElement('div');
        canvasWrapper.style.position = 'relative';
        canvasWrapper.style.display = 'inline-block';
        
        // Create canvas
        this.canvas = document.createElement('canvas');
        this.canvas.className = 'pdf-canvas';
        this.ctx = this.canvas.getContext('2d');
        
        canvasWrapper.appendChild(this.canvas);
        container.appendChild(canvasWrapper);
        this.canvasWrapper = canvasWrapper;
    }
    
    /**
     * Render specific page
     */
    async renderPage(pageNum) {
        if (pageNum < 1 || pageNum > this.totalPages) {
            return;
        }
        
        try {
            this.isLoading = true;
            const page = await this.pdfDoc.getPage(pageNum);
            
            // Set canvas dimensions
            const viewport = page.getViewport({ scale: this.scale });
            this.canvas.width = viewport.width;
            this.canvas.height = viewport.height;
            
            // Render page
            const renderContext = {
                canvasContext: this.ctx,
                viewport: viewport
            };
            
            await page.render(renderContext).promise;
            
            this.currentPage = pageNum;
            this.updatePageInfo();
            this.renderAnnotations(pageNum);
            
            this.isLoading = false;
        } catch (error) {
            console.error('Error rendering page:', error);
            this.showError('Failed to render page');
        }
    }
    
    /**
     * Go to next page
     */
    async nextPage() {
        if (this.currentPage < this.totalPages) {
            await this.renderPage(this.currentPage + 1);
        }
    }
    
    /**
     * Go to previous page
     */
    async previousPage() {
        if (this.currentPage > 1) {
            await this.renderPage(this.currentPage - 1);
        }
    }
    
    /**
     * Go to specific page
     */
    async goToPage(pageNum) {
        const page = parseInt(pageNum);
        if (page >= 1 && page <= this.totalPages) {
            await this.renderPage(page);
        }
    }
    
    /**
     * Zoom in
     */
    async zoomIn() {
        this.scale += 0.25;
        await this.renderPage(this.currentPage);
    }
    
    /**
     * Zoom out
     */
    async zoomOut() {
        if (this.scale > 0.5) {
            this.scale -= 0.25;
            await this.renderPage(this.currentPage);
        }
    }
    
    /**
     * Reset zoom
     */
    async resetZoom() {
        this.scale = 1.5;
        await this.renderPage(this.currentPage);
    }
    
    /**
     * Set annotations
     */
    setAnnotations(annotations) {
        this.annotations = annotations || [];
        this.renderAnnotations(this.currentPage);
    }
    
    /**
     * Render annotations for current page
     */
    renderAnnotations(pageNum) {
        // Clear previous annotation overlays
        if (this.canvasWrapper) {
            const existingOverlays = this.canvasWrapper.querySelectorAll('.annotation-overlay');
            existingOverlays.forEach(overlay => overlay.remove());
        }
        
        if (!this.annotations || this.annotations.length === 0) {
            return;
        }
        
        // Filter annotations for current page
        const pageAnnotations = this.annotations.filter(ann => ann.page_number === pageNum);
        
        if (pageAnnotations.length === 0) {
            return;
        }
        
        // Render each annotation
        pageAnnotations.forEach(annotation => {
            const overlay = this.createAnnotationOverlay(annotation);
            if (overlay) {
                this.canvasWrapper.appendChild(overlay);
            }
        });
    }
    
    /**
     * Create annotation overlay element
     */
    createAnnotationOverlay(annotation) {
        const overlay = document.createElement('div');
        overlay.className = 'annotation-overlay';
        overlay.dataset.annotationId = annotation.annotation_id;
        overlay.dataset.annotationType = annotation.annotation_type;
        
        // Use percentage-based positioning for responsive scaling
        // This ensures overlays stay aligned when viewport is resized or zoomed
        overlay.style.position = 'absolute';
        overlay.style.left = annotation.x_coordinate + '%';
        overlay.style.top = annotation.y_coordinate + '%';
        overlay.style.width = (annotation.position_width || 5) + '%';
        overlay.style.height = (annotation.position_height || 5) + '%';
        
        // Set minimum size in pixels to ensure visibility
        overlay.style.minWidth = '30px';
        overlay.style.minHeight = '30px';
        
        // Set color based on type
        const colors = {
            'comment': 'rgba(255, 193, 7, 0.3)',
            'highlight': 'rgba(255, 255, 0, 0.4)',
            'suggestion': 'rgba(135, 206, 235, 0.3)'
        };
        overlay.style.backgroundColor = colors[annotation.annotation_type] || colors['comment'];
        
        // Add border for better visibility
        const borderColors = {
            'comment': '#ffc107',
            'highlight': '#ffeb3b',
            'suggestion': '#17a2b8'
        };
        overlay.style.border = `2px solid ${borderColors[annotation.annotation_type] || '#ffc107'}`;
        overlay.style.borderRadius = '4px';
        
        // Add click handler to highlight in sidebar
        overlay.addEventListener('click', (e) => {
            e.stopPropagation();
            this.highlightAnnotationInSidebar(annotation.annotation_id);
            if (this.onAnnotationClick) {
                this.onAnnotationClick(annotation);
            }
        });
        
        // Add title for hover
        overlay.title = `${annotation.annotation_type}: ${annotation.annotation_content.substring(0, 50)}...`;
        
        return overlay;
    }
    
    /**
     * Highlight annotation in sidebar with pulse animation
     */
    highlightAnnotationInSidebar(annotationId) {
        // Remove previous highlights
        document.querySelectorAll('.comment-item.highlighted').forEach(item => {
            item.classList.remove('highlighted');
        });
        
        // Find and highlight the corresponding comment
        const commentItem = document.querySelector(`.comment-item[data-annotation-id="${annotationId}"]`);
        if (commentItem) {
            // Scroll into view first
            commentItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Add highlight after a brief delay to ensure scroll completes
            setTimeout(() => {
                commentItem.classList.add('highlighted');
            }, 200);
            
            // Remove highlight after animation completes
            setTimeout(() => {
                commentItem.classList.remove('highlighted');
            }, 2500);
        }
    }
    
    /**
     * Highlight overlay on PDF with pulse animation
     */
    highlightOverlayOnPDF(annotationId) {
        // Remove previous pulse animations
        document.querySelectorAll('.annotation-overlay.pulse').forEach(overlay => {
            overlay.classList.remove('pulse');
        });
        
        // Find and pulse the corresponding overlay
        const overlay = document.querySelector(`.annotation-overlay[data-annotation-id="${annotationId}"]`);
        if (overlay) {
            // Scroll overlay into view if not visible
            const container = document.getElementById(this.containerId);
            if (container) {
                const overlayRect = overlay.getBoundingClientRect();
                const containerRect = container.getBoundingClientRect();
                
                // Check if overlay is outside viewport
                if (overlayRect.top < containerRect.top || overlayRect.bottom > containerRect.bottom ||
                    overlayRect.left < containerRect.left || overlayRect.right > containerRect.right) {
                    overlay.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
            
            // Add pulse after a brief delay to ensure scroll completes
            setTimeout(() => {
                overlay.classList.add('pulse');
            }, 200);
            
            // Remove pulse class after animation
            setTimeout(() => {
                overlay.classList.remove('pulse');
            }, 700);
        }
    }
    
    /**
     * Update page info display
     */
    updatePageInfo() {
        const pageInfo = document.querySelector('.pdf-page-info');
        if (pageInfo) {
            pageInfo.textContent = `Page ${this.currentPage} of ${this.totalPages}`;
        }
    }
    
    /**
     * Show loading state
     */
    showLoadingState() {
        const container = document.getElementById(this.containerId);
        if (container) {
            container.innerHTML = '<div class="loading-spinner"><p>Loading PDF...</p></div>';
        }
    }
    
    /**
     * Hide loading state
     */
    hideLoadingState() {
        const spinner = document.querySelector('.loading-spinner');
        if (spinner) {
            spinner.remove();
        }
    }
    
    /**
     * Show error message
     */
    showError(message) {
        // Try to use the messageContainer above the viewer first
        const messageContainer = document.getElementById('messageContainer');
        if (messageContainer) {
            messageContainer.innerHTML = `
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Error!</strong> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            // Scroll to top to show the error
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            // Fallback: show in container if messageContainer not found
            const container = document.getElementById(this.containerId);
            if (container) {
                container.innerHTML = `<div class="error-message"><p>${message}</p></div>`;
            }
        }
    }
    
    /**
     * Get current page number
     */
    getCurrentPage() {
        return this.currentPage;
    }
    
    /**
     * Get total pages
     */
    getTotalPages() {
        return this.totalPages;
    }
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PDFViewer;
}
