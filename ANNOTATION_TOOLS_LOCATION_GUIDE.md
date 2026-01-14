# Annotation Tools Location Guide

## Quick Answer

**Annotation Tools are ONLY on the ADVISER Review Page:**

📍 **Page:** `adviser_pdf_review.php?submission_id={id}`
📍 **Who Can Use:** Advisers, Committee Chairpersons, Panel Members
📍 **Tools Available:** Comment, Highlight, Suggestion

---

## PART 1: WHERE TO FIND ANNOTATION TOOLS

### Step-by-Step Navigation

```
1. ADVISER LOGS IN
   └─ adviser.php (Adviser Dashboard)

2. ADVISER SEES PDF REVIEWS SECTION
   └─ Scroll down to "📋 PDF REVIEWS"
      ├─ Statistics Cards
      ├─ Pending Review Table
      ├─ In Progress Table
      └─ Completed Table

3. ADVISER CLICKS [REVIEW] BUTTON
   └─ Pending Review Table
      └─ Student: John Castro
         File: thesis_final.pdf
         [Review] ← CLICK HERE
         
4. ADVISER OPENS REVIEW PAGE
   └─ adviser_pdf_review.php?submission_id=1
      ├─ PDF Viewer (Left side)
      ├─ Annotation Toolbar (Below PDF)
      │  ├─ 💬 Comment Tool
      │  ├─ 🖍️ Highlight Tool
      │  └─ 💡 Suggestion Tool
      └─ Comment Panel (Right side)
         └─ Shows all annotations
```

---

## PART 2: ANNOTATION TOOLS VISUAL LAYOUT

### Adviser PDF Review Page Layout

```
┌─────────────────────────────────────────────────────────────────────────┐
│  ADVISER PDF REVIEW PAGE (adviser_pdf_review.php?submission_id=1)       │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │ PDF TOOLBAR                                                     │  │
│  │ [◀ Previous] Page 1 of 10 [Next ▶]  [−] 100% [+] [Reset]      │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │ ANNOTATION TOOLBAR ← ✅ ANNOTATION TOOLS ARE HERE              │  │
│  │ ┌──────────────────────────────────────────────────────────┐   │  │
│  │ │ [💬 Comment] [🖍️ Highlight] [💡 Suggestion]             │   │  │
│  │ └──────────────────────────────────────────────────────────┘   │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                                                         │
│  ┌──────────────────────────────────────┐  ┌──────────────────────┐  │
│  │                                      │  │  COMMENT PANEL       │  │
│  │                                      │  │  ┌────────────────┐  │  │
│  │                                      │  │  │ Annotations    │  │  │
│  │                                      │  │  │ ┌────────────┐ │  │  │
│  │         PDF VIEWER                   │  │  │ │ Comment 1  │ │  │  │
│  │                                      │  │  │ │ by Adviser │ │  │  │
│  │  (Click to add annotations)          │  │  │ │            │ │  │  │
│  │                                      │  │  │ │ [Reply]    │ │  │  │
│  │                                      │  │  │ └────────────┘ │  │  │
│  │                                      │  │  │ ┌────────────┐ │  │  │
│  │                                      │  │  │ │ Comment 2  │ │  │  │
│  │                                      │  │  │ │ by Adviser │ │  │  │
│  │                                      │  │  │ │            │ │  │  │
│  │                                      │  │  │ │ [Reply]    │ │  │  │
│  │                                      │  │  │ └────────────┘ │  │  │
│  │                                      │  │  └────────────────┘  │  │
│  │                                      │  └──────────────────────┘  │
│  └──────────────────────────────────────┘                            │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## PART 3: ANNOTATION TOOLS EXPLAINED

### Tool 1: Comment Tool 💬

**What it does:** Add text comments at specific locations on the PDF

**How to use:**
1. Click [💬 Comment] button in Annotation Toolbar
2. Click on PDF where you want to add comment
3. Dialog appears: "Add Annotation"
4. Select Type: "Comment"
5. Type your comment text
6. Click [Save Annotation]

**Example:**
```
Comment: "Please clarify this methodology section"
Location: Page 3, middle of page
```

### Tool 2: Highlight Tool 🖍️

**What it does:** Highlight important text sections

**How to use:**
1. Click [🖍️ Highlight] button in Annotation Toolbar
2. Click on PDF where you want to highlight
3. Dialog appears: "Add Annotation"
4. Select Type: "Highlight"
5. Type your note about the highlight
6. Click [Save Annotation]

**Example:**
```
Highlight: "This section needs revision"
Location: Page 5, paragraph 2
```

### Tool 3: Suggestion Tool 💡

**What it does:** Provide suggestions for improvement

**How to use:**
1. Click [💡 Suggestion] button in Annotation Toolbar
2. Click on PDF where you want to add suggestion
3. Dialog appears: "Add Annotation"
4. Select Type: "Suggestion"
5. Type your suggestion
6. Click [Save Annotation]

**Example:**
```
Suggestion: "Consider adding more recent references from 2023-2024"
Location: Page 8, references section
```

---

## PART 4: COMPLETE WORKFLOW TO USE ANNOTATION TOOLS

### Step 1: Adviser Logs In
```
URL: http://localhost/IAdS_Ni/login.php
Username: adviser_username
Password: adviser_password
Role: Adviser
```

### Step 2: Go to Adviser Dashboard
```
URL: http://localhost/IAdS_Ni/adviser.php
Section: PDF Reviews
```

### Step 3: See Pending Submissions
```
Table: Pending Review
Shows:
├─ Student Name
├─ File Name
├─ Submitted Date
└─ [Review] Button ← CLICK HERE
```

### Step 4: Open PDF Review Page
```
URL: http://localhost/IAdS_Ni/adviser_pdf_review.php?submission_id=1
Page Shows:
├─ PDF Viewer (Left)
├─ Annotation Toolbar (Below PDF) ← ANNOTATION TOOLS HERE
│  ├─ [💬 Comment]
│  ├─ [🖍️ Highlight]
│  └─ [💡 Suggestion]
└─ Comment Panel (Right)
```

### Step 5: Use Annotation Tools
```
1. Click annotation tool button
2. Click on PDF to place annotation
3. Fill in annotation dialog
4. Click [Save Annotation]
5. Annotation appears in Comment Panel
6. Student sees annotation when viewing feedback
```

---

## PART 5: ANNOTATION TOOLS CODE LOCATION

### In `adviser_pdf_review.php`

```html
<!-- Annotation Toolbar Section -->
<div class="pdf-toolbar">
    <div class="annotation-toolbar">
        <button class="annotation-tool-btn" data-tool="comment" title="Add Comment">
            <i class="bi bi-chat-left-text"></i> Comment
        </button>
        <button class="annotation-tool-btn" data-tool="highlight" title="Highlight Text">
            <i class="bi bi-highlighter"></i> Highlight
        </button>
        <button class="annotation-tool-btn" data-tool="suggestion" title="Add Suggestion">
            <i class="bi bi-lightbulb"></i> Suggestion
        </button>
    </div>
</div>
```

### In `annotation_manager.js`

```javascript
// Tool selection handler
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
```

### In `pdf_annotation_styles.css`

```css
.annotation-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: var(--secondary-color);
    border-radius: 6px;
}

.annotation-tool-btn {
    padding: 8px 12px;
    border: 1px solid var(--primary-color);
    background: white;
    color: var(--primary-color);
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}

.annotation-tool-btn:hover {
    background: var(--primary-color);
    color: white;
}

.annotation-tool-btn.active {
    background: var(--primary-color);
    color: white;
}
```

---

## PART 6: WHAT STUDENTS SEE (NO ANNOTATION TOOLS)

### Student PDF View Page

```
┌─────────────────────────────────────────────────────────────────────────┐
│  STUDENT PDF VIEW PAGE (student_pdf_view.php?submission_id=1)           │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │ PDF TOOLBAR                                                     │  │
│  │ [◀ Previous] Page 1 of 10 [Next ▶]  [−] 100% [+] [Reset]      │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                                                         │
│  ⚠️ NO ANNOTATION TOOLBAR HERE (Students cannot create annotations)    │
│                                                                         │
│  ┌──────────────────────────────────────┐  ┌──────────────────────┐  │
│  │                                      │  │  COMMENT PANEL       │  │
│  │                                      │  │  (READ-ONLY)         │  │
│  │                                      │  │  ┌────────────────┐  │  │
│  │         PDF VIEWER                   │  │  │ Adviser        │  │  │
│  │                                      │  │  │ Feedback       │  │  │
│  │  (Shows adviser annotations)         │  │  │ ┌────────────┐ │  │  │
│  │                                      │  │  │ │ Comment 1  │ │  │  │
│  │                                      │  │  │ │ by Adviser │ │  │  │
│  │                                      │  │  │ │            │ │  │  │
│  │                                      │  │  │ │ [Reply]    │ │  │  │
│  │                                      │  │  │ └────────────┘ │  │  │
│  │                                      │  │  └────────────────┘  │  │
│  │                                      │  └──────────────────────┘  │
│  └──────────────────────────────────────┘                            │
│                                                                         │
│  [📤 Upload Revised PDF]                                              │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

**Key Difference:**
- ❌ NO Annotation Toolbar
- ❌ NO Comment Tool
- ❌ NO Highlight Tool
- ❌ NO Suggestion Tool
- ✅ Can only VIEW adviser annotations
- ✅ Can REPLY to annotations
- ✅ Can UPLOAD revised PDF

---

## PART 7: COMPARISON TABLE

| Feature | Adviser Page | Student Page |
|---------|--------------|--------------|
| **Page Name** | adviser_pdf_review.php | student_pdf_view.php |
| **URL** | adviser_pdf_review.php?submission_id=1 | student_pdf_view.php?submission_id=1 |
| **PDF Viewer** | ✅ Yes | ✅ Yes |
| **Annotation Toolbar** | ✅ **YES** | ❌ No |
| **Comment Tool** | ✅ **YES** | ❌ No |
| **Highlight Tool** | ✅ **YES** | ❌ No |
| **Suggestion Tool** | ✅ **YES** | ❌ No |
| **Create Annotations** | ✅ **YES** | ❌ No |
| **View Annotations** | ✅ Yes | ✅ Yes |
| **Reply to Annotations** | ✅ Yes | ✅ Yes |
| **Upload Revision** | ❌ No | ✅ Yes |

---

## PART 8: ANNOTATION TOOLS FEATURES

### Comment Tool Features
```
✅ Add text comments
✅ Specify location on PDF
✅ Save to database
✅ Display in comment panel
✅ Student can reply
✅ Adviser can edit
✅ Adviser can delete
✅ Adviser can resolve
```

### Highlight Tool Features
```
✅ Highlight text sections
✅ Add note about highlight
✅ Specify location on PDF
✅ Save to database
✅ Display in comment panel
✅ Student can reply
✅ Adviser can edit
✅ Adviser can delete
✅ Adviser can resolve
```

### Suggestion Tool Features
```
✅ Provide improvement suggestions
✅ Specify location on PDF
✅ Save to database
✅ Display in comment panel
✅ Student can reply
✅ Adviser can edit
✅ Adviser can delete
✅ Adviser can resolve
```

---

## PART 9: ANNOTATION DIALOG

### When Adviser Clicks Annotation Tool

```
┌─────────────────────────────────────────────────────┐
│  Add Annotation                              [×]    │
├─────────────────────────────────────────────────────┤
│                                                     │
│  Type                                              │
│  [Comment ▼]                                        │
│  Options: Comment, Highlight, Suggestion           │
│                                                     │
│  Content                                            │
│  ┌─────────────────────────────────────────────┐   │
│  │ Enter your annotation...                    │   │
│  │                                             │   │
│  │                                             │   │
│  └─────────────────────────────────────────────┘   │
│                                                     │
│  Selected Text (if any)                            │
│  "This is the selected text from PDF"              │
│                                                     │
├─────────────────────────────────────────────────────┤
│  [Cancel]  [Save Annotation]                       │
└─────────────────────────────────────────────────────┘
```

---

## SUMMARY

| Item | Answer |
|------|--------|
| **Where are annotation tools?** | `adviser_pdf_review.php` page |
| **Who can use them?** | Advisers, Committee Chairpersons, Panel Members |
| **How to access?** | adviser.php → PDF Reviews → [Review] button |
| **Tools available** | Comment, Highlight, Suggestion |
| **Can students use them?** | ❌ No, only advisers |
| **Can students see them?** | ❌ No, not on their page |
| **Can students reply?** | ✅ Yes, to adviser annotations |

---

## QUICK NAVIGATION

```
To Use Annotation Tools:

1. Login as Adviser
   └─ adviser.php

2. Go to PDF Reviews
   └─ Scroll to "📋 PDF REVIEWS"

3. Click [Review] Button
   └─ adviser_pdf_review.php?submission_id=1

4. See Annotation Toolbar
   └─ [💬 Comment] [🖍️ Highlight] [💡 Suggestion]

5. Click Tool → Click PDF → Fill Dialog → Save
   └─ Annotation created and saved
```
