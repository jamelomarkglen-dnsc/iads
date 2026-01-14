/**
 * Annotation Manager JavaScript
 * Handles annotation creation, display, and interaction
 * 
 * @package IAdS
 * @subpackage PDF Annotation System
 */

class AnnotationManager {
    constructor(options = {}) {
        this.submissionId = options.submissionId || 0;
        this.userId = options.userId || 0;
        this.userRole = options.userRole || '';
        this.pdfViewer = options.pdfViewer || null;
        this.apiEndpoint = options.apiEndpoint || 'pdf_annotation_api.php';
        this.annotations = [];
        this.selectedTool = null;
        this.isCreatingAnnotation = false;
        this.commentPanel = null;
        this.annotationDialog = null;
        this.isDragging = false;
        this.dragStart = null;
        this.dragPreview = null;
        
        this.init();
    }
    
    /**
     * Initialize annotation manager
     */
    init() {
        this.setupEventListeners();
        this.loadAnnotations();
    }
    
    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Annotation toolbar buttons
        const toolButtons = document.querySelectorAll('.annotation-tool-btn');
        toolButtons.forEach(btn => {
            btn.addEventListener('click', (e) => this.handleToolClick(e));
        });
        
        // PDF canvas drag for annotation creation
        const container = document.getElementById('pdf-canvas-container');
        if (container) {
            container.addEventListener('mousedown', (e) => this.handleMouseDown(e));
            container.addEventListener('mousemove', (e) => this.handleMouseMove(e));
            container.addEventListener('mouseup', (e) => this.handleMouseUp(e));
        }
        
        // Comment panel
        this.commentPanel = document.querySelector('.comment-panel-content');
        
        // Annotation dialog
        this.annotationDialog = document.querySelector('.annotation-dialog');
        if (this.annotationDialog) {
            const closeBtn = this.annotationDialog.querySelector('.annotation-dialog-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => this.closeDialog());
            }
            
            const submitBtn = this.annotationDialog.querySelector('.annotation-dialog-btn.primary');
            if (submitBtn) {
                submitBtn.addEventListener('click', () => this.submitAnnotation());
            }
            
            const cancelBtn = this.annotationDialog.querySelector('.annotation-dialog-btn.secondary');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => this.closeDialog());
            }
        }
    }
    
    /**
     * Handle annotation tool click
     */
    handleToolClick(event) {
        const btn = event.currentTarget;
        const tool = btn.dataset.tool;
        
        // Toggle tool selection
        if (this.selectedTool === tool) {
            this.selectedTool = null;
            btn.classList.remove('active');
        } else {
            // Deselect previous tool
            document.querySelectorAll('.annotation-tool-btn.active').forEach(b => {
                b.classList.remove('active');
            });
            
            this.selectedTool = tool;
            btn.classList.add('active');
        }
    }
    
    /**
     * Handle mouse down for drag start
     */
    handleMouseDown(event) {
        if (!this.selectedTool || this.userRole === 'student') {
            return;
        }
        
        // Check if clicking on annotation overlay or buttons
        if (event.target.classList.contains('annotation-overlay') ||
            event.target.closest('.annotation-tool-btn') ||
            event.target.closest('.pdf-toolbar')) {
            return;
        }
        
        // Get canvas
        const canvas = document.querySelector('.pdf-canvas');
        if (!canvas) return;
        
        const rect = canvas.getBoundingClientRect();
        
        // Check if click is on canvas
        if (event.clientX < rect.left || event.clientX > rect.right ||
            event.clientY < rect.top || event.clientY > rect.bottom) {
            return;
        }
        
        // Start dragging
        this.isDragging = true;
        this.dragStart = {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
            canvasRect: rect
        };
        
        // Create preview element
        this.createDragPreview();
        
        event.preventDefault();
    }
    
    /**
     * Handle mouse move for drag preview
     */
    handleMouseMove(event) {
        if (!this.isDragging || !this.dragStart || !this.dragPreview) {
            return;
        }
        
        const canvas = document.querySelector('.pdf-canvas');
        if (!canvas) return;
        
        const rect = canvas.getBoundingClientRect();
        const currentX = event.clientX - rect.left;
        const currentY = event.clientY - rect.top;
        
        // Calculate dimensions in pixels
        const left = Math.min(this.dragStart.x, currentX);
        const top = Math.min(this.dragStart.y, currentY);
        const width = Math.abs(currentX - this.dragStart.x);
        const height = Math.abs(currentY - this.dragStart.y);
        
        // Convert to percentages using displayed canvas size (rect), not internal dimensions
        // This ensures the preview appears exactly where the user is dragging
        const leftPercent = (left / rect.width) * 100;
        const topPercent = (top / rect.height) * 100;
        const widthPercent = (width / rect.width) * 100;
        const heightPercent = (height / rect.height) * 100;
        
        // Update preview position and size using percentages
        this.dragPreview.style.left = leftPercent + '%';
        this.dragPreview.style.top = topPercent + '%';
        this.dragPreview.style.width = widthPercent + '%';
        this.dragPreview.style.height = heightPercent + '%';
    }
    
    /**
     * Handle mouse up for drag end
     */
    handleMouseUp(event) {
        if (!this.isDragging || !this.dragStart) {
            return;
        }
        
        const canvas = document.querySelector('.pdf-canvas');
        if (!canvas) {
            this.cancelDrag();
            return;
        }
        
        const rect = canvas.getBoundingClientRect();
        const endX = event.clientX - rect.left;
        const endY = event.clientY - rect.top;
        
        // Calculate dimensions
        const left = Math.min(this.dragStart.x, endX);
        const top = Math.min(this.dragStart.y, endY);
        const width = Math.abs(endX - this.dragStart.x);
        const height = Math.abs(endY - this.dragStart.y);
        
        // Minimum size check (at least 10px)
        if (width < 10 || height < 10) {
            this.cancelDrag();
            return;
        }
        
        // Convert to percentages using displayed canvas size (rect), not internal dimensions
        // This ensures the saved annotation matches where the user dragged
        const xPercent = (left / rect.width) * 100;
        const yPercent = (top / rect.height) * 100;
        const widthPercent = (width / rect.width) * 100;
        const heightPercent = (height / rect.height) * 100;
        
        // Store annotation data
        this.currentAnnotationData = {
            x_coordinate: Math.round(xPercent * 100) / 100,
            y_coordinate: Math.round(yPercent * 100) / 100,
            position_width: Math.round(widthPercent * 100) / 100,
            position_height: Math.round(heightPercent * 100) / 100,
            page_number: this.pdfViewer.getCurrentPage(),
            annotation_type: this.selectedTool,
            selected_text: this.getSelectedText()
        };
        
        // Stop dragging but keep preview visible
        this.isDragging = false;
        this.dragStart = null;
        
        // Show dialog (preview will be removed when dialog closes)
        this.showDialog();
    }
    
    /**
     * Create drag preview element
     */
    createDragPreview() {
        const canvas = document.querySelector('.pdf-canvas');
        if (!canvas) return;
        
        // Remove existing preview
        if (this.dragPreview) {
            this.dragPreview.remove();
        }
        
        // Create preview
        this.dragPreview = document.createElement('div');
        this.dragPreview.className = 'annotation-drag-preview';
        this.dragPreview.style.position = 'absolute';
        this.dragPreview.style.pointerEvents = 'none';
        this.dragPreview.style.zIndex = '999';
        this.dragPreview.style.boxSizing = 'border-box';
        
        // Set color based on tool type
        const colors = {
            'comment': 'rgba(255, 193, 7, 0.3)',
            'highlight': 'rgba(255, 255, 0, 0.4)',
            'suggestion': 'rgba(135, 206, 235, 0.3)'
        };
        this.dragPreview.style.backgroundColor = colors[this.selectedTool] || colors['comment'];
        
        const borderColors = {
            'comment': '#ffc107',
            'highlight': '#ffeb3b',
            'suggestion': '#17a2b8'
        };
        this.dragPreview.style.border = `2px dashed ${borderColors[this.selectedTool] || '#ffc107'}`;
        this.dragPreview.style.borderRadius = '4px';
        
        // Add to canvas wrapper
        const canvasWrapper = canvas.parentElement;
        if (canvasWrapper) {
            canvasWrapper.appendChild(this.dragPreview);
        }
    }
    
    /**
     * Cancel drag operation
     */
    cancelDrag() {
        this.isDragging = false;
        this.dragStart = null;
        
        if (this.dragPreview) {
            this.dragPreview.remove();
            this.dragPreview = null;
        }
    }
    
    /**
     * Get selected text from page
     */
    getSelectedText() {
        const selection = window.getSelection();
        return selection.toString().substring(0, 200) || '';
    }
    
    /**
     * Show annotation dialog
     */
    showDialog() {
        if (!this.annotationDialog) return;
        
        // Set annotation type
        const typeSelect = this.annotationDialog.querySelector('select[name="annotation_type"]');
        if (typeSelect) {
            typeSelect.value = this.currentAnnotationData.annotation_type;
        }
        
        // Set selected text if available
        const selectedTextDiv = this.annotationDialog.querySelector('.selected-text-display');
        if (selectedTextDiv && this.currentAnnotationData.selected_text) {
            selectedTextDiv.textContent = `"${this.currentAnnotationData.selected_text}"`;
            selectedTextDiv.style.display = 'block';
        }
        
        // Clear content
        const contentTextarea = this.annotationDialog.querySelector('textarea[name="annotation_content"]');
        if (contentTextarea) {
            contentTextarea.value = '';
            contentTextarea.focus();
        }
        
        // Show dialog
        this.annotationDialog.classList.add('show');
    }
    
    /**
     * Close annotation dialog
     */
    closeDialog() {
        if (this.annotationDialog) {
            this.annotationDialog.classList.remove('show');
        }
        
        // Remove drag preview when dialog closes
        if (this.dragPreview) {
            this.dragPreview.remove();
            this.dragPreview = null;
        }
        
        // Deselect tool
        document.querySelectorAll('.annotation-tool-btn.active').forEach(btn => {
            btn.classList.remove('active');
        });
        this.selectedTool = null;
    }
    
    /**
     * Submit annotation
     */
    async submitAnnotation() {
        if (!this.currentAnnotationData) return;
        
        // Get form data
        const contentTextarea = this.annotationDialog.querySelector('textarea[name="annotation_content"]');
        const typeSelect = this.annotationDialog.querySelector('select[name="annotation_type"]');
        
        const annotationContent = contentTextarea.value.trim();
        const annotationType = typeSelect.value;
        
        if (!annotationContent) {
            this.showMessage('Please enter annotation content', 'error');
            return;
        }
        
        // Prepare data
        const formData = new FormData();
        formData.append('action', 'create_annotation');
        formData.append('submission_id', this.submissionId);
        formData.append('annotation_type', annotationType);
        formData.append('annotation_content', annotationContent);
        formData.append('page_number', this.currentAnnotationData.page_number);
        formData.append('x_coordinate', this.currentAnnotationData.x_coordinate);
        formData.append('y_coordinate', this.currentAnnotationData.y_coordinate);
        formData.append('position_width', this.currentAnnotationData.position_width || 5);
        formData.append('position_height', this.currentAnnotationData.position_height || 5);
        formData.append('selected_text', this.currentAnnotationData.selected_text);
        
        try {
            // Log the data being sent for debugging
            console.log('Creating annotation with data:', {
                submission_id: this.submissionId,
                annotation_type: annotationType,
                page_number: this.currentAnnotationData.page_number,
                x_coordinate: this.currentAnnotationData.x_coordinate,
                y_coordinate: this.currentAnnotationData.y_coordinate,
                content_length: annotationContent.length
            });
            
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                body: formData
            });
            
            // Log response status
            console.log('API Response Status:', response.status, response.statusText);
            
            // Get response text first to handle both JSON and non-JSON responses
            const responseText = await response.text();
            console.log('API Response Text:', responseText);
            
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Failed to parse JSON response:', parseError);
                this.showMessage(`Server Error: Invalid JSON response. Status: ${response.status}. Response: ${responseText.substring(0, 200)}`, 'error');
                return;
            }
            
            if (result.success) {
                this.showMessage('Annotation created successfully', 'success');
                
                // Remove drag preview on successful submission
                if (this.dragPreview) {
                    this.dragPreview.remove();
                    this.dragPreview = null;
                }
                
                this.closeDialog();
                this.loadAnnotations();
            } else {
                // Show detailed error message
                const errorMsg = result.error || 'Failed to create annotation';
                const detailedError = result.details ? ` Details: ${JSON.stringify(result.details)}` : '';
                console.error('Annotation creation failed:', result);
                this.showMessage(`${errorMsg}${detailedError}`, 'error');
            }
        } catch (error) {
            console.error('Error creating annotation:', error);
            this.showMessage(`Error creating annotation: ${error.message}. Check console for details.`, 'error');
        }
    }
    
    /**
     * Load annotations from server
     */
    async loadAnnotations() {
        const formData = new FormData();
        formData.append('action', 'fetch_annotations');
        formData.append('submission_id', this.submissionId);
        
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.annotations = result.annotations || [];
                this.renderCommentPanel();
                
                if (this.pdfViewer) {
                    this.pdfViewer.setAnnotations(this.annotations);
                }
            }
        } catch (error) {
            console.error('Error loading annotations:', error);
        }
    }
    
    /**
     * Render comment panel
     */
    renderCommentPanel() {
        if (!this.commentPanel) return;
        
        this.commentPanel.innerHTML = '';
        
        if (this.annotations.length === 0) {
            this.commentPanel.innerHTML = '<p style="padding: 12px; color: #999; text-align: center;">No annotations yet</p>';
            return;
        }
        
        // Group annotations by page
        const byPage = {};
        this.annotations.forEach(ann => {
            if (!byPage[ann.page_number]) {
                byPage[ann.page_number] = [];
            }
            byPage[ann.page_number].push(ann);
        });
        
        // Render annotations
        Object.keys(byPage).sort((a, b) => a - b).forEach(pageNum => {
            const pageAnnotations = byPage[pageNum];
            
            pageAnnotations.forEach(annotation => {
                const item = this.createCommentItem(annotation);
                this.commentPanel.appendChild(item);
            });
        });
    }
    
    /**
     * Create comment item element
     */
    createCommentItem(annotation) {
        const item = document.createElement('div');
        item.className = 'comment-item';
        item.dataset.annotationId = annotation.annotation_id;
        
        // Add click handler to highlight overlay on PDF
        item.style.cursor = 'pointer';
        item.addEventListener('click', (e) => {
            // Don't trigger if clicking on action buttons
            if (!e.target.classList.contains('comment-action-btn')) {
                if (this.pdfViewer && typeof this.pdfViewer.highlightOverlayOnPDF === 'function') {
                    this.pdfViewer.highlightOverlayOnPDF(annotation.annotation_id);
                }
            }
        });
        
        // Header
        const header = document.createElement('div');
        header.className = 'comment-header';
        header.innerHTML = `
            <div>
                <div class="comment-author">${annotation.adviser_name}</div>
                <div class="comment-time">${this.formatTime(annotation.creation_timestamp)}</div>
            </div>
            <div class="comment-type-badge ${annotation.annotation_type}">${this.getTypeLabel(annotation.annotation_type)}</div>
        `;
        item.appendChild(header);
        
        // Content
        const content = document.createElement('div');
        content.className = 'comment-content';
        content.textContent = annotation.annotation_content;
        item.appendChild(content);
        
        // Selected text
        if (annotation.selected_text) {
            const selectedText = document.createElement('div');
            selectedText.className = 'comment-selected-text';
            selectedText.textContent = `"${annotation.selected_text}"`;
            item.appendChild(selectedText);
        }
        
        // Replies
        if (annotation.replies && annotation.replies.length > 0) {
            const repliesDiv = document.createElement('div');
            repliesDiv.className = 'comment-replies';
            
            annotation.replies.forEach(reply => {
                const replyItem = document.createElement('div');
                replyItem.className = 'reply-item';
                replyItem.innerHTML = `
                    <div class="reply-author">${reply.user_name}</div>
                    <div class="reply-content">${reply.reply_content}</div>
                    <div class="reply-time">${this.formatTime(reply.reply_timestamp)}</div>
                `;
                repliesDiv.appendChild(replyItem);
            });
            
            item.appendChild(repliesDiv);
        }
        
        // Actions
        const actions = document.createElement('div');
        actions.className = 'comment-actions';
        
        if (this.userRole === 'student' || this.userRole === 'adviser') {
            const replyBtn = document.createElement('button');
            replyBtn.className = 'comment-action-btn';
            replyBtn.textContent = 'Reply';
            replyBtn.addEventListener('click', (e) => {
                e.stopPropagation(); // Prevent triggering item click
                this.showReplyDialog(annotation.annotation_id);
            });
            actions.appendChild(replyBtn);
        }
        
        if (this.userRole === 'adviser' && annotation.adviser_id === this.userId) {
            const resolveBtn = document.createElement('button');
            resolveBtn.className = 'comment-action-btn';
            resolveBtn.textContent = annotation.annotation_status === 'active' ? 'Resolve' : 'Unresolve';
            resolveBtn.addEventListener('click', (e) => {
                e.stopPropagation(); // Prevent triggering item click
                this.toggleResolve(annotation.annotation_id);
            });
            actions.appendChild(resolveBtn);
            
            const deleteBtn = document.createElement('button');
            deleteBtn.className = 'comment-action-btn comment-action-delete';
            deleteBtn.innerHTML = '<i class="bi bi-trash"></i> Delete';
            deleteBtn.style.position = 'relative';
            deleteBtn.addEventListener('click', (e) => {
                e.stopPropagation(); // Prevent triggering item click
                this.showDeleteConfirmation(annotation.annotation_id, deleteBtn);
            });
            actions.appendChild(deleteBtn);
        }
        
        item.appendChild(actions);
        
        return item;
    }
    
    /**
     * Show reply dialog
     */
    showReplyDialog(annotationId) {
        const replyContent = prompt('Enter your reply:');
        if (replyContent) {
            this.addReply(annotationId, replyContent);
        }
    }
    
    /**
     * Add reply to annotation
     */
    async addReply(annotationId, replyContent) {
        const formData = new FormData();
        formData.append('action', 'add_reply');
        formData.append('annotation_id', annotationId);
        formData.append('reply_content', replyContent);
        
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showMessage('Reply added successfully', 'success');
                this.loadAnnotations();
            } else {
                this.showMessage(result.error || 'Failed to add reply', 'error');
            }
        } catch (error) {
            console.error('Error adding reply:', error);
            this.showMessage('Error adding reply', 'error');
        }
    }
    
    /**
     * Toggle annotation resolve status
     */
    async toggleResolve(annotationId) {
        const formData = new FormData();
        formData.append('action', 'resolve_annotation');
        formData.append('annotation_id', annotationId);
        
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showMessage('Annotation status updated', 'success');
                this.loadAnnotations();
            } else {
                this.showMessage(result.error || 'Failed to update annotation', 'error');
            }
        } catch (error) {
            console.error('Error updating annotation:', error);
            this.showMessage('Error updating annotation', 'error');
        }
    }
    
    /**
     * Show delete confirmation tooltip
     */
    showDeleteConfirmation(annotationId, deleteBtn) {
        // Remove any existing confirmation tooltips
        document.querySelectorAll('.delete-confirmation-tooltip').forEach(t => t.remove());
        
        // Create confirmation tooltip
        const tooltip = document.createElement('div');
        tooltip.className = 'delete-confirmation-tooltip';
        tooltip.innerHTML = `
            <div class="delete-confirm-text">Delete?</div>
            <div class="delete-confirm-actions">
                <button class="delete-confirm-btn delete-confirm-yes">Yes</button>
                <button class="delete-confirm-btn delete-confirm-no">No</button>
            </div>
        `;
        
        // Add tooltip to button
        deleteBtn.appendChild(tooltip);
        
        // Handle Yes button
        tooltip.querySelector('.delete-confirm-yes').addEventListener('click', (e) => {
            e.stopPropagation();
            tooltip.remove();
            this.deleteAnnotation(annotationId);
        });
        
        // Handle No button
        tooltip.querySelector('.delete-confirm-no').addEventListener('click', (e) => {
            e.stopPropagation();
            tooltip.remove();
        });
        
        // Close on outside click
        setTimeout(() => {
            const closeHandler = (e) => {
                if (!tooltip.contains(e.target) && !deleteBtn.contains(e.target)) {
                    tooltip.remove();
                    document.removeEventListener('click', closeHandler);
                }
            };
            document.addEventListener('click', closeHandler);
        }, 100);
    }
    
    /**
     * Delete annotation
     */
    async deleteAnnotation(annotationId) {
        const formData = new FormData();
        formData.append('action', 'delete_annotation');
        formData.append('annotation_id', annotationId);
        
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showMessage('Annotation deleted successfully', 'success');
                this.loadAnnotations();
            } else {
                this.showMessage(result.error || 'Failed to delete annotation', 'error');
            }
        } catch (error) {
            console.error('Error deleting annotation:', error);
            this.showMessage('Error deleting annotation', 'error');
        }
    }
    
    /**
     * Format timestamp
     */
    formatTime(timestamp) {
        const date = new Date(timestamp);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    }
    
    /**
     * Get annotation type label
     */
    getTypeLabel(type) {
        const labels = {
            'comment': 'Comment',
            'highlight': 'Highlight',
            'suggestion': 'Suggestion'
        };
        return labels[type] || type;
    }
    
    /**
     * Show message - delegates to global showNotification function
     */
    showMessage(message, type = 'info') {
        if (typeof window.showNotification === 'function') {
            window.showNotification(message, type);
        } else {
            console.error('showNotification function not found');
            console.log(message);
        }
    }
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AnnotationManager;
}
