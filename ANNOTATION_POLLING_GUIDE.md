# Annotation Polling Implementation Guide

## Overview
The PDF annotation system now supports semi-real-time updates through polling. This allows users to see new annotations and replies as they are added by other users without manually refreshing the page.

## How It Works

### Architecture
1. **Client-Side Polling**: JavaScript polls the server at regular intervals
2. **Change Detection**: Compares new data with cached data to detect changes
3. **Smart Updates**: Only re-renders when actual changes are detected
4. **Resource Optimization**: Pauses polling when page is hidden

### Implementation Details

#### AnnotationManager Configuration
```javascript
const annotationManager = new AnnotationManager({
    submissionId: 123,
    userId: 456,
    userRole: 'student',
    pdfViewer: pdfViewer,
    apiEndpoint: 'pdf_annotation_api.php',
    enablePolling: true,           // Enable polling
    pollingInterval: 3000          // Poll every 3 seconds (3000ms)
});
```

#### Key Features

1. **Automatic Polling**
   - Starts automatically when `enablePolling: true`
   - Default interval: 3 seconds
   - Configurable via `pollingInterval` option

2. **Change Detection**
   - Detects new annotations
   - Detects new replies
   - Detects annotation deletions
   - Detects status changes (resolved/active)

3. **Smart Notifications**
   - Shows "Annotations updated" message when changes detected
   - Silent updates when no changes
   - No notification spam

4. **Resource Management**
   - Pauses polling when browser tab is hidden
   - Resumes polling when tab becomes visible
   - Immediate update when resuming

5. **Performance Optimization**
   - Minimal server load (3-second intervals)
   - Efficient change detection algorithm
   - No unnecessary re-renders

## Usage

### For Students (student_pdf_view.php)
Polling is enabled by default with 3-second intervals. Students will see:
- New annotations from advisers
- New replies to existing annotations
- Updates to annotation status

### For Advisers (adviser_pdf_review.php)
Polling is enabled by default with 3-second intervals. Advisers will see:
- New replies from students
- Changes made by other advisers (if applicable)

## Configuration Options

### Polling Interval
Adjust the polling frequency by changing `pollingInterval`:

```javascript
// Poll every 1 second (more frequent, higher server load)
pollingInterval: 1000

// Poll every 5 seconds (less frequent, lower server load)
pollingInterval: 5000

// Poll every 10 seconds (minimal server load)
pollingInterval: 10000
```

### Disable Polling
To disable polling for specific use cases:

```javascript
enablePolling: false
```

## API Methods

### Start Polling
```javascript
annotationManager.startPolling();
```

### Stop Polling
```javascript
annotationManager.stopPolling();
```

### Pause Polling (Temporary)
```javascript
annotationManager.pausePolling();
```

### Resume Polling
```javascript
annotationManager.resumePolling();
```

## Performance Considerations

### Server Load
- **3-second interval**: ~20 requests per minute per user
- **5-second interval**: ~12 requests per minute per user
- **10-second interval**: ~6 requests per minute per user

### Recommendations
- **Active collaboration**: 3-second interval (default)
- **Normal usage**: 5-second interval
- **Low-traffic periods**: 10-second interval

### Automatic Optimization
The system automatically:
- Pauses polling when browser tab is hidden
- Resumes polling when tab becomes visible
- Reduces unnecessary server requests

## Browser Compatibility
- Modern browsers with `fetch()` API support
- Page Visibility API for pause/resume functionality
- Works on all major browsers (Chrome, Firefox, Safari, Edge)

## Troubleshooting

### Polling Not Working
1. Check browser console for errors
2. Verify `enablePolling: true` is set
3. Check network tab for API requests
4. Ensure `pdf_annotation_api.php` is accessible

### High Server Load
1. Increase `pollingInterval` value
2. Consider disabling polling for some users
3. Monitor server resources

### Delayed Updates
1. Decrease `pollingInterval` for faster updates
2. Check network latency
3. Verify server response time

## Security Considerations
- All polling requests use existing authentication
- Access control enforced on every request
- No additional security risks introduced
- Same permissions as manual refresh

## Future Enhancements
Potential improvements for future versions:
- WebSocket support for true real-time updates
- Adaptive polling (faster when active, slower when idle)
- Push notifications for critical updates
- Batch updates for multiple annotations

## Testing

### Manual Testing
1. Open PDF in two browser windows
2. Add annotation in one window
3. Verify it appears in other window within polling interval
4. Test with different user roles (student/adviser)

### Console Logging
Enable debug logging:
```javascript
console.log('Starting annotation polling (interval: 3000ms)');
console.log('Pausing annotation polling');
console.log('Resuming annotation polling');
console.log('Stopping annotation polling');
```

## Summary
The polling implementation provides a seamless, semi-real-time collaboration experience with minimal server overhead and smart resource management. It's enabled by default for both students and advisers with a 3-second polling interval.