import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

/**
 * AvatarEditor controller.
 *
 * Hover-reveal pencil/cross overlay on the profile avatar (profile
 * header only — see the `avatarEditForm`-gated block in
 * account/_profile_header.html.twig, only passed on
 * /account/profile-settings). The pencil opens the OS file picker
 * directly (the underlying `<input type="file">` stays hidden); once a
 * file is chosen, a square-crop dialog (Cropper.js) opens, and "Save"
 * there submits the surrounding form immediately — a real multipart
 * POST + full-page redirect back to the hub, the same PRG flow as
 * every other settings form in this app, just triggered by the dialog
 * instead of a separate submit button. The cross opens a confirm
 * dialog; confirming ticks Vich's own delete checkbox and submits the
 * same way.
 *
 * Markup contract:
 *
 *   [data-controller-root="avatar-editor"]
 *     data-target-size                  square output size in px (default 512)
 *     [data-avatar-editor-form]         the wrapping <form>
 *     [data-avatar-file-input]          hidden file input
 *     [data-avatar-delete-checkbox]     hidden checkbox (only present when
 *                                       the user already has a photo)
 *     [data-avatar-edit-trigger]        pencil <button>
 *     [data-avatar-delete-trigger]      cross <button> (only present
 *                                       alongside the checkbox)
 *     dialog[data-avatar-crop-dialog]
 *       img[data-crop-target]                 Cropper.js attaches here
 *       input[type=range][data-crop-zoom]     relative zoom control
 *       [data-crop-cancel] / [data-crop-save]
 *     dialog[data-avatar-delete-dialog]
 *       [data-avatar-delete-cancel] / [data-avatar-delete-confirm]
 *
 * If this module fails to load, the pencil/cross overlay is simply
 * inert — there's no plain file input fallback here (unlike the old
 * dedicated edit-profile page this replaces), since the whole point of
 * the widget is the hover affordance. The server independently
 * enforces a square ratio regardless (see EditProfileFormType).
 *
 * Idempotent: each root is bound once via a marker property, so the
 * module can be imported/init'd multiple times without double-binding
 * (mirrors custom_select.js).
 */
class AvatarEditorController {
    constructor(root) {
        this.root = root;
        this.targetSize = parseInt(root.dataset.targetSize || '512', 10);

        this.form = root.querySelector('[data-avatar-editor-form]');
        this.input = root.querySelector('[data-avatar-file-input]');
        this.editTrigger = root.querySelector('[data-avatar-edit-trigger]');
        this.deleteTrigger = root.querySelector('[data-avatar-delete-trigger]');
        this.deleteCheckbox = root.querySelector('[data-avatar-delete-checkbox]');

        this.cropDialog = root.querySelector('[data-avatar-crop-dialog]');
        this.cropImage = root.querySelector('[data-crop-target]');
        this.zoomInput = root.querySelector('[data-crop-zoom]');
        this.cropSaveBtn = root.querySelector('[data-crop-save]');
        this.cropCancelBtn = root.querySelector('[data-crop-cancel]');

        this.deleteDialog = root.querySelector('[data-avatar-delete-dialog]');
        this.deleteCancelBtn = root.querySelector('[data-avatar-delete-cancel]');
        this.deleteConfirmBtn = root.querySelector('[data-avatar-delete-confirm]');

        if (!this.form || !this.input || !this.editTrigger || !this.cropDialog || !this.cropImage) return;

        this.cropper = null;
        this.objectUrl = null;
        this.zoomValue = 0;

        this.editTrigger.addEventListener('click', () => this.input.click());
        this.input.addEventListener('change', (e) => this.#onFileChange(e));
        this.cropSaveBtn?.addEventListener('click', () => this.#saveCrop());
        this.cropCancelBtn?.addEventListener('click', () => this.#cancelCrop());
        this.cropDialog.addEventListener('close', () => this.#teardownCropper());

        if (this.zoomInput) {
            this.zoomInput.addEventListener('input', () => this.#onZoomInput());
        }

        if (this.deleteTrigger && this.deleteDialog && this.deleteCheckbox) {
            this.deleteTrigger.addEventListener('click', () => this.deleteDialog.showModal());
            this.deleteCancelBtn?.addEventListener('click', () => this.deleteDialog.close());
            this.deleteConfirmBtn?.addEventListener('click', () => this.#confirmDelete());
        }
    }

    #onFileChange(e) {
        const file = e.target.files && e.target.files[0];
        if (!file || !file.type.startsWith('image/')) return;

        this.#revokeObjectUrl();
        this.objectUrl = URL.createObjectURL(file);
        this.cropImage.src = this.objectUrl;

        this.cropDialog.showModal();

        // Cropper needs the image actually decoded/laid out first.
        if (this.cropImage.complete) {
            this.#initCropper();
        } else {
            this.cropImage.addEventListener('load', () => this.#initCropper(), { once: true });
        }
    }

    #initCropper() {
        this.cropper = new Cropper(this.cropImage, {
            aspectRatio: 1,
            viewMode: 1,
            dragMode: 'move',
            cropBoxResizable: false,
            cropBoxMovable: false,
            toggleDragModeOnDblclick: false,
            autoCropArea: 1,
            background: false,
            zoomOnWheel: true,
        });
        this.zoomValue = 0;
        if (this.zoomInput) this.zoomInput.value = '0';
    }

    #onZoomInput() {
        if (!this.cropper) return;
        const next = parseFloat(this.zoomInput.value);
        // The slider represents a delta from center, not an absolute
        // ratio — Cropper's `zoom()` is relative, so we feed it the
        // difference since the last input event.
        this.cropper.zoom(next - this.zoomValue);
        this.zoomValue = next;
    }

    #saveCrop() {
        if (!this.cropper) return;
        const canvas = this.cropper.getCroppedCanvas({
            width: this.targetSize,
            height: this.targetSize,
            imageSmoothingQuality: 'high',
        });
        if (!canvas) return;

        canvas.toBlob((blob) => {
            if (!blob) return;

            const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
            const transfer = new DataTransfer();
            transfer.items.add(file);
            this.input.files = transfer.files;

            this.cropDialog.close();
            this.form.requestSubmit();
        }, 'image/jpeg', 0.92);
    }

    #cancelCrop() {
        this.input.value = '';
        this.cropDialog.close();
    }

    #teardownCropper() {
        if (this.cropper) {
            this.cropper.destroy();
            this.cropper = null;
        }
        this.#revokeObjectUrl();
    }

    #revokeObjectUrl() {
        if (this.objectUrl) {
            URL.revokeObjectURL(this.objectUrl);
            this.objectUrl = null;
        }
    }

    #confirmDelete() {
        this.deleteCheckbox.checked = true;
        this.deleteDialog.close();
        this.form.requestSubmit();
    }
}

function init() {
    document.querySelectorAll('[data-controller-root="avatar-editor"]').forEach((root) => {
        if (root.__avatarEditorInstance) return;
        root.__avatarEditorInstance = new AvatarEditorController(root);
    });
}

function initWithin(scope) {
    (scope || document).querySelectorAll('[data-controller-root="avatar-editor"]').forEach((root) => {
        if (root.__avatarEditorInstance) return;
        root.__avatarEditorInstance = new AvatarEditorController(root);
    });
}

window.AvatarEditor = { init, initWithin, AvatarEditorController };

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

export { AvatarEditorController, init, initWithin };
