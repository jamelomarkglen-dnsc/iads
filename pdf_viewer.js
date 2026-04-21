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
        this.textLayer = null;
        this.isLoading = false;
        this.annotations = [];
        this.onAnnotationClick = options.onAnnotationClick || null;
        this.searchQuery = '';
        this.searchRawQuery = '';
        this.searchMatches = [];
        this.currentSearchIndex = -1;
        this.searchInput = null;
        this.searchCount = null;
        this.searchStatus = null;
        this.searchPrevBtn = null;
        this.searchNextBtn = null;
        this.searchClearBtn = null;
        this.searchResultsPanel = null;
        this.searchResultsList = null;
        this.searchResultsCount = null;
        this.searchDebounceTimer = null;
        this.searchToken = 0;
        this.pageTextCache = new Map();
        
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
            this.setupSearchControls();
            
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
        canvasWrapper.style.alignSelf = 'flex-start';
        canvasWrapper.style.justifySelf = 'flex-start';
        
        // Create canvas
        this.canvas = document.createElement('canvas');
        this.canvas.className = 'pdf-canvas';
        this.ctx = this.canvas.getContext('2d');

        canvasWrapper.appendChild(this.canvas);

        this.textLayer = document.createElement('div');
        this.textLayer.className = 'pdf-text-layer';
        canvasWrapper.appendChild(this.textLayer);

        container.appendChild(canvasWrapper);
        this.searchResultsPanel = document.createElement('aside');
        this.searchResultsPanel.className = 'pdf-search-results-panel';
        this.searchResultsPanel.innerHTML = `
            <div class="pdf-search-results-header">
                <div>
                    <div class="pdf-search-results-title">Search Results</div>
                    <div class="pdf-search-results-subtitle">Matches grouped by page</div>
                </div>
                <span class="pdf-search-results-count">0</span>
            </div>
            <div class="pdf-search-results-list">
                <div class="pdf-search-results-empty">Type a word or phrase to find matches in the document.</div>
            </div>
        `;
        container.appendChild(this.searchResultsPanel);
        this.searchResultsList = this.searchResultsPanel.querySelector('.pdf-search-results-list');
        this.searchResultsCount = this.searchResultsPanel.querySelector('.pdf-search-results-count');
        this.canvasWrapper = canvasWrapper;
        this.scrollToTop();
    }

    /**
     * Setup WPS-style PDF search controls
     */
    setupSearchControls() {
        const toolbarLeft = document.querySelector('.pdf-toolbar-left');
        if (!toolbarLeft || toolbarLeft.querySelector('.pdf-search-controls')) {
            return;
        }

        const searchControls = document.createElement('div');
        searchControls.className = 'pdf-search-controls';
        searchControls.innerHTML = `
            <label class="small text-muted mb-0 pdf-search-label">Find</label>
            <div class="pdf-search-box">
                <span class="pdf-search-icon" aria-hidden="true">
                    <i class="bi bi-search"></i>
                </span>
                <input type="search" class="pdf-search-input" placeholder="Search words or letters" aria-label="Search in PDF">
                <button type="button" class="pdf-search-btn pdf-search-prev" title="Previous match" aria-label="Previous match">
                    <i class="bi bi-chevron-up"></i>
                </button>
                <button type="button" class="pdf-search-btn pdf-search-next" title="Next match" aria-label="Next match">
                    <i class="bi bi-chevron-down"></i>
                </button>
                <button type="button" class="pdf-search-btn pdf-search-clear" title="Clear search" aria-label="Clear search">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <span class="pdf-search-count">0/0</span>
            <span class="pdf-search-status">Type to search</span>
        `;

        toolbarLeft.appendChild(searchControls);

        this.searchInput = searchControls.querySelector('.pdf-search-input');
        this.searchCount = searchControls.querySelector('.pdf-search-count');
        this.searchStatus = searchControls.querySelector('.pdf-search-status');
        this.searchPrevBtn = searchControls.querySelector('.pdf-search-prev');
        this.searchNextBtn = searchControls.querySelector('.pdf-search-next');
        this.searchClearBtn = searchControls.querySelector('.pdf-search-clear');

        if (this.searchInput) {
            this.searchInput.addEventListener('input', () => this.debouncedSearch(this.searchInput.value));
            this.searchInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    this.searchNow(this.searchInput.value);
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    this.clearSearch();
                    this.searchInput.value = '';
                }
            });
        }

        if (this.searchPrevBtn) {
            this.searchPrevBtn.addEventListener('click', () => this.previousSearchMatch());
        }

        if (this.searchNextBtn) {
            this.searchNextBtn.addEventListener('click', () => this.nextSearchMatch());
        }

        if (this.searchClearBtn) {
            this.searchClearBtn.addEventListener('click', () => {
                if (this.searchInput) {
                    this.searchInput.value = '';
                }
                this.clearSearch();
            });
        }

        this.updateSearchControls();
        this.updateSearchResultsPanel();
    }

    /**
     * Debounce search input
     */
    debouncedSearch(query) {
        clearTimeout(this.searchDebounceTimer);
        this.searchDebounceTimer = setTimeout(() => {
            this.searchNow(query);
        }, 250);
    }

    /**
     * Normalize search text for matching
     */
    normalizeSearchText(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();
    }

    /**
     * Escape HTML for safe text rendering
     */
    escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Escape text for use inside a regular expression
     */
    escapeRegExp(text) {
        return String(text || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Build a whole-word search pattern for a query
     */
    buildSearchPattern(query) {
        const normalizedQuery = this.normalizeSearchText(query);
        if (!normalizedQuery) {
            return null;
        }

        const parts = normalizedQuery.split(' ').filter(Boolean).map(part => this.escapeRegExp(part));
        if (!parts.length) {
            return null;
        }

        const pattern = parts.join('\\s*');
        return new RegExp(`(^|[^\\w])(${pattern})(?=$|[^\\w])`, 'gi');
    }

    /**
     * Find all occurrences of a needle in a haystack
     */
    findOccurrences(haystack, needle) {
        const matches = [];
        if (!haystack || !needle) {
            return matches;
        }

        let index = 0;
        while (index >= 0) {
            index = haystack.indexOf(needle, index);
            if (index === -1) {
                break;
            }
            matches.push(index);
            index += Math.max(1, needle.length);
        }

        return matches;
    }

    /**
     * Clear all search state
     */
    clearSearch() {
        this.searchToken += 1;
        this.searchQuery = '';
        this.searchRawQuery = '';
        this.searchMatches = [];
        this.currentSearchIndex = -1;
        this.clearSearchHighlights();
        this.updateSearchControls();
        this.updateSearchResultsPanel();
        if (this.pdfDoc && this.currentPage) {
            void this.renderTextLayer(this.currentPage);
        }
    }

    /**
     * Search now
     */
    async searchNow(query) {
        const rawQuery = String(query || '').trim();
        const normalizedQuery = this.normalizeSearchText(rawQuery);
        this.searchRawQuery = rawQuery;
        this.searchQuery = normalizedQuery;
        this.searchToken += 1;
        const token = this.searchToken;

        if (!normalizedQuery) {
            this.clearSearch();
            return;
        }

        this.searchMatches = [];
        this.currentSearchIndex = -1;
        this.updateSearchControls(true);

        if (!this.pdfDoc || !this.totalPages) {
            this.updateSearchControls();
            return;
        }

        try {
            for (let pageNum = 1; pageNum <= this.totalPages; pageNum += 1) {
                if (token !== this.searchToken) {
                    return;
                }
                const pageMatches = await this.findMatchesInPage(pageNum, normalizedQuery);
                this.searchMatches.push(...pageMatches);
            }

            if (token !== this.searchToken) {
                return;
            }

            this.searchMatches.sort((a, b) => {
                if (a.pageNumber !== b.pageNumber) {
                    return a.pageNumber - b.pageNumber;
                }
                if (a.startIndex !== b.startIndex) {
                    return a.startIndex - b.startIndex;
                }
                return (a.occurrenceIndex || 0) - (b.occurrenceIndex || 0);
            });

            this.currentSearchIndex = this.searchMatches.length > 0 ? 0 : -1;
            this.updateSearchControls();
            this.updateSearchResultsPanel();

            if (this.currentSearchIndex >= 0) {
                await this.goToSearchMatch(this.currentSearchIndex);
            } else {
                this.clearSearchHighlights();
            }
        } catch (error) {
            console.error('Error searching PDF:', error);
            this.updateSearchControls();
        }
    }

    /**
     * Find matches in a specific page
     */
    async findMatchesInPage(pageNum, normalizedQuery) {
        if (this.pageTextCache.has(pageNum)) {
            return this.collectPageMatches(pageNum, this.pageTextCache.get(pageNum), normalizedQuery, this.searchRawQuery);
        }

        const page = await this.pdfDoc.getPage(pageNum);
        const index = await this.buildPageTextIndex(page);
        this.pageTextCache.set(pageNum, index);
        return this.collectPageMatches(pageNum, index, normalizedQuery, this.searchRawQuery);
    }

    /**
     * Build a searchable page text index with item offsets
     */
    async buildPageTextIndex(page) {
        const textContent = await page.getTextContent();
        const items = [];
        let fullText = '';
        let cursor = 0;

        (textContent.items || []).forEach((item, itemIndex) => {
            const str = String(item.str || '');
            const start = cursor;
            const end = start + str.length;

            items.push({
                itemIndex,
                str,
                transform: item.transform,
                width: item.width || 0,
                height: item.height || 0,
                start,
                end
            });

            fullText += str;
            cursor = end;
        });

        return { items, fullText };
    }

    /**
     * Collect matching items for a page
     */
    collectPageMatches(pageNum, cachedPage, normalizedQuery, rawQuery) {
        const matches = [];
        const items = cachedPage?.items || [];
        const fullText = String(cachedPage?.fullText || '');
        if (!items.length || !fullText) {
            return matches;
        }

        const regex = this.buildSearchPattern(rawQuery || normalizedQuery);
        if (!regex) {
            return matches;
        }

        let occurrenceIndex = 0;
        let match;
        while ((match = regex.exec(fullText)) !== null) {
            const queryIndex = match.index + (match[1] ? match[1].length : 0);
            const queryText = match[2] || '';
            const startIndex = queryIndex;
            const endIndex = startIndex + queryText.length;
            const snippetRadius = 24;
            const snippetStart = Math.max(0, startIndex - snippetRadius);
            const snippetEnd = Math.min(fullText.length, endIndex + snippetRadius);
            const snippet = `${snippetStart > 0 ? '…' : ''}${fullText.slice(snippetStart, snippetEnd).replace(/\s+/g, ' ').trim()}${snippetEnd < fullText.length ? '…' : ''}`;

            matches.push({
                pageNumber: pageNum,
                occurrenceIndex: occurrenceIndex++,
                text: queryText,
                snippet,
                startIndex,
                endIndex,
                queryLength: queryText.length,
                itemRanges: items
                    .filter(item => item.end > startIndex && item.start < endIndex)
                    .map(item => ({
                        itemIndex: item.itemIndex,
                        startOffset: Math.max(0, startIndex - item.start),
                        endOffset: Math.min(item.str.length, endIndex - item.start),
                        item
                    }))
            });
        }

        return matches;
    }

    /**
     * Estimate text item rectangle in viewport coordinates
     */
    getTextItemRect(item, viewport) {
        if (!item || !item.transform || !viewport || !pdfjsLib?.Util) {
            return null;
        }

        try {
            const tx = pdfjsLib.Util.transform(viewport.transform, item.transform);
            const x = tx[4];
            const y = tx[5];
            const height = Math.max(Math.hypot(tx[2], tx[3]) || 0, item.height || 0, 10);
            const width = Math.max(item.width || 0, 1) * this.scale;
            return {
                left: x,
                top: y - height,
                width,
                height: height + 2
            };
        } catch (error) {
            console.warn('Unable to calculate search highlight bounds:', error);
            return null;
        }
    }

    /**
     * Move to the previous search result
     */
    async previousSearchMatch() {
        if (!this.searchMatches.length) {
            return;
        }

        this.currentSearchIndex = (this.currentSearchIndex - 1 + this.searchMatches.length) % this.searchMatches.length;
        await this.goToSearchMatch(this.currentSearchIndex);
    }

    /**
     * Move to the next search result
     */
    async nextSearchMatch() {
        if (!this.searchMatches.length) {
            return;
        }

        this.currentSearchIndex = (this.currentSearchIndex + 1) % this.searchMatches.length;
        await this.goToSearchMatch(this.currentSearchIndex);
    }

    /**
     * Go to a specific search result
     */
    async goToSearchMatch(index, silent = false) {
        const match = this.searchMatches[index];
        if (!match) {
            return;
        }

        if (this.currentPage !== match.pageNumber) {
            await this.renderPage(match.pageNumber);
        } else {
            await this.renderTextLayer(this.currentPage);
        }

        this.currentSearchIndex = index;
        this.updateSearchControls();
        this.updateSearchResultsPanel();

        const activeOverlay = this.textLayer?.querySelector(`.pdf-search-hit[data-search-index="${index}"]`);
        if (activeOverlay) {
            activeOverlay.classList.add('active');
            if (!silent) {
                setTimeout(() => {
                    activeOverlay.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
                }, 150);
            }
        }
    }

    /**
     * Render search highlights for the current page
     */
    renderSearchHighlights(pageNum) {
        return this.renderTextLayer(pageNum);
    }

    /**
     * Render the searchable results panel grouped by page
     */
    updateSearchResultsPanel() {
        if (!this.searchResultsList || !this.searchResultsCount || !this.searchResultsPanel) {
            return;
        }

        const totalMatches = this.searchMatches.length;
        this.searchResultsCount.textContent = String(totalMatches);

        if (!this.searchQuery) {
            this.searchResultsList.innerHTML = '<div class="pdf-search-results-empty">Type a word or phrase to find matches in the document.</div>';
            return;
        }

        if (!totalMatches) {
            this.searchResultsList.innerHTML = '<div class="pdf-search-results-empty">No matches found.</div>';
            return;
        }

        const query = this.searchRawQuery || this.searchQuery;
        const queryRegex = new RegExp(this.escapeRegExp(query), 'ig');
        const pageGroups = new Map();
        this.searchMatches.forEach((match, index) => {
            if (!pageGroups.has(match.pageNumber)) {
                pageGroups.set(match.pageNumber, []);
            }
            pageGroups.get(match.pageNumber).push({ match, index });
        });

        const html = Array.from(pageGroups.entries()).map(([pageNumber, items]) => {
            const entries = items.map(({ match, index }) => {
                const snippet = this.escapeHtml(match.snippet || match.text || '');
                const highlightedSnippet = query
                    ? snippet.replace(queryRegex, found => `<mark>${found}</mark>`)
                    : snippet;
                const activeClass = index === this.currentSearchIndex ? ' active' : '';
                return `
                    <button type="button" class="pdf-search-result-item${activeClass}" data-search-index="${index}">
                        <div class="pdf-search-result-meta">
                            <span class="pdf-search-result-order">#${index + 1}</span>
                            <span class="pdf-search-result-page">Page ${pageNumber}</span>
                        </div>
                        <div class="pdf-search-result-snippet">${highlightedSnippet}</div>
                    </button>
                `;
            }).join('');

            return `
                <section class="pdf-search-result-group">
                    <div class="pdf-search-result-group-title">
                        <span>Page ${pageNumber}</span>
                        <span>${items.length} match${items.length > 1 ? 'es' : ''}</span>
                    </div>
                    <div class="pdf-search-result-group-items">
                        ${entries}
                    </div>
                </section>
            `;
        }).join('');

        this.searchResultsList.innerHTML = html;
        this.searchResultsList.querySelectorAll('.pdf-search-result-item').forEach((button) => {
            button.addEventListener('click', () => {
                const index = parseInt(button.dataset.searchIndex || '-1', 10);
                if (index >= 0) {
                    void this.goToSearchMatch(index);
                }
            });
        });
    }

    /**
     * Render a searchable text layer over the current page
     */
    async renderTextLayer(pageNum) {
        if (!this.textLayer || !this.pdfDoc || !this.canvas) {
            return;
        }

        this.textLayer.innerHTML = '';

        const page = await this.pdfDoc.getPage(pageNum);
        const viewport = page.getViewport({ scale: this.scale });
        this.currentViewport = viewport;
        
        // IMPORTANT: Set text layer dimensions to exactly match canvas for perfect alignment
        this.textLayer.style.width = `${viewport.width}px`;
        this.textLayer.style.height = `${viewport.height}px`;

        const cachedPage = this.pageTextCache.get(pageNum);
        let items = cachedPage?.items || [];
        if (!items.length) {
            const textContent = await page.getTextContent();
            items = (textContent.items || []).map((item, itemIndex) => ({
                itemIndex,
                str: item.str || '',
                transform: item.transform,
                width: item.width || 0,
                height: item.height || 0
            }));
            this.pageTextCache.set(pageNum, { items });
        }

        items.forEach((item) => {
            const span = document.createElement('span');
            span.className = 'pdf-text-span';
            span.dataset.itemIndex = String(item.itemIndex);
            span.dataset.originalText = item.str || '';
            span.style.position = 'absolute';
            span.style.whiteSpace = 'pre';
            span.style.transformOrigin = '0 0';
            span.style.pointerEvents = 'none';
            span.style.color = 'transparent';
            span.style.webkitTextFillColor = 'transparent';

            if (item.transform && pdfjsLib?.Util) {
                const tx = pdfjsLib.Util.transform(viewport.transform, item.transform);
                span.style.transform = `matrix(${tx[0]}, ${tx[1]}, ${tx[2]}, ${tx[3]}, ${tx[4]}, ${tx[5]})`;
                span.style.fontSize = `${Math.max(1, Math.hypot(tx[2], tx[3]) || 1)}px`;
            } else {
                span.style.left = '0px';
                span.style.top = '0px';
                span.style.fontSize = '12px';
            }

            span.textContent = item.str || '';
            this.textLayer.appendChild(span);
        });

        const currentMatch = this.searchMatches[this.currentSearchIndex];
        if (currentMatch && currentMatch.pageNumber === pageNum && !this.isLoading) {
            setTimeout(() => {
                const pageEl = this.canvasWrapper || this.textLayer;
                pageEl?.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
            }, 80);
        }
    }

    /**
     * Remove search highlight overlays
     */
    clearSearchHighlights() {
        if (this.textLayer) {
            this.textLayer.innerHTML = '';
        }
    }

    /**
     * Update search control text
     */
    updateSearchControls(isSearching = false) {
        const totalMatches = this.searchMatches.length;
        const currentMatch = this.currentSearchIndex >= 0 ? this.currentSearchIndex + 1 : 0;

        if (this.searchCount) {
            this.searchCount.textContent = `${currentMatch}/${totalMatches}`;
        }

        if (this.searchStatus) {
            if (isSearching) {
                this.searchStatus.textContent = 'Searching...';
            } else if (!this.searchQuery) {
                this.searchStatus.textContent = 'Type to search';
            } else if (totalMatches === 0) {
                this.searchStatus.textContent = 'No matches';
            } else {
                this.searchStatus.textContent = 'Match found';
            }
        }

        const hasMatches = totalMatches > 0;
        if (this.searchPrevBtn) {
            this.searchPrevBtn.disabled = !hasMatches;
        }
        if (this.searchNextBtn) {
            this.searchNextBtn.disabled = !hasMatches;
        }
        if (this.searchClearBtn) {
            this.searchClearBtn.disabled = !this.searchQuery;
        }
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
            this.currentViewport = viewport;
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
            await this.renderSearchHighlights(pageNum);
            this.renderAnnotations(pageNum);
            this.updateSearchResultsPanel();
            this.scrollToTop();
            
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
            if (overlay && this.canvasWrapper) {
                this.canvasWrapper.appendChild(overlay);
            } else if (overlay && !this.canvasWrapper) {
                console.warn('Canvas wrapper not ready, skipping annotation overlay');
            }
        });
    }
    
    /**
     * Create annotation overlay element
     */
    createAnnotationOverlay(annotation) {
        const overlay = document.createElement('div');
        overlay.className = 'annotation-overlay';
        const annotationType = String(annotation.annotation_type || 'comment').toLowerCase();
        overlay.dataset.annotationId = annotation.annotation_id;
        overlay.dataset.annotationType = annotationType;
        overlay.classList.add(`annotation-type-${annotationType}`);
        
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
        overlay.style.zIndex = '4';
        
        const palette = {
            comment: {
                backgroundColor: 'rgba(255, 193, 7, 0.18)',
                borderColor: '#ffc107'
            },
            highlight: {
                backgroundColor: 'rgba(13, 110, 253, 0.16)',
                borderColor: '#0d6efd'
            },
            suggestion: {
                backgroundColor: 'rgba(25, 135, 84, 0.16)',
                borderColor: '#198754'
            }
        };
        const theme = palette[annotationType] || palette.comment;
        overlay.style.backgroundColor = theme.backgroundColor;
        overlay.style.border = `2px solid ${theme.borderColor}`;
        overlay.style.borderRadius = '4px';
        overlay.style.boxShadow = `0 0 0 1px ${theme.borderColor}22`;
        
        // Add click handler to highlight in sidebar
        overlay.addEventListener('click', (e) => {
            e.stopPropagation();
            this.highlightAnnotationInSidebar(annotation.annotation_id);
            if (this.onAnnotationClick) {
                this.onAnnotationClick(annotation);
            }
        });
        
        // Add title for hover
        const label = annotationType.charAt(0).toUpperCase() + annotationType.slice(1);
        overlay.title = `${label}: ${String(annotation.annotation_content || '').substring(0, 50)}...`;
        
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
     * Ensure the top-left of the page is visible after render
     */
    scrollToTop() {
        const container = document.getElementById(this.containerId);
        if (container) {
            container.scrollTo({ top: 0, left: 0 });
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
