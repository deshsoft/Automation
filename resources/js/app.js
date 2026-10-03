const PLATFORM_LABELS = { facebook: 'Facebook', instagram: 'Instagram', youtube: 'YouTube', tiktok: 'TikTok' };

const composer = document.querySelector('[data-composer]');

if (composer) {
    setUpComposer(composer);
}

function setUpComposer(form) {
    const accountBoxes = [...form.querySelectorAll('[data-account]')];
    const selectAll = form.querySelector('[data-select-all]');
    const caption = form.querySelector('#caption');
    const captionCount = form.querySelector('[data-caption-count]');
    const mediaInput = form.querySelector('[data-media-input]');
    const dropzone = form.querySelector('[data-dropzone]');
    const shareFrom = form.querySelector('[data-share-from]');
    const shareFields = form.querySelector('[data-share-fields]');
    const scheduleFields = form.querySelector('[data-schedule-fields]');
    const tabs = form.querySelector('[data-preview-tabs]');

    let mediaUrl = null;
    let mediaKind = null;
    let previewPlatform = null;

    const selectedAccounts = () => accountBoxes.filter((box) => box.checked);
    const selectedPlatforms = () => [...new Set(selectedAccounts().map((box) => box.dataset.platform))];

    // --- Accounts and platform specific sections ---------------------------

    function refreshAccounts() {
        const platforms = selectedPlatforms();

        form.querySelectorAll('[data-platform-section]').forEach((section) => {
            const sectionPlatforms = section.dataset.platformSection.split(' ');
            section.classList.toggle('hidden', !sectionPlatforms.some((platform) => platforms.includes(platform)));
        });

        refreshShareOptions();
        refreshTabs();
        refreshMediaWarning();
        renderPreview();
    }

    selectAll?.addEventListener('change', () => {
        accountBoxes.forEach((box) => (box.checked = selectAll.checked));
        refreshAccounts();
    });
    accountBoxes.forEach((box) => box.addEventListener('change', refreshAccounts));

    // --- Facebook share mode ---------------------------------------------

    function refreshShareOptions() {
        const pages = selectedAccounts().filter((box) => box.dataset.platform === 'facebook');
        const previous = shareFrom.value || shareFrom.dataset.old;

        shareFrom.replaceChildren(
            ...pages.map((box) => {
                const option = new Option(box.dataset.name, box.value);
                option.selected = box.value === previous;
                return option;
            }),
        );
    }

    form.querySelectorAll('[data-share-mode]').forEach((radio) => {
        radio.addEventListener('change', () => {
            shareFields.classList.toggle('hidden', !(radio.checked && radio.value === 'share'));
        });
    });

    // --- Caption ------------------------------------------------------------

    const updateCount = () => (captionCount.textContent = caption.value.length);
    caption.addEventListener('input', () => {
        updateCount();
        renderPreview();
    });
    form.querySelectorAll('[data-platform-caption]').forEach((field) => field.addEventListener('input', renderPreview));
    form.querySelector('[data-import-input]')?.addEventListener('input', () => {
        refreshMediaWarning();
        renderPreview();
    });

    const linkInput = form.querySelector('[data-link-input]');
    let linkTimer = null;

    linkInput.addEventListener('input', () => {
        const link = linkInput.value.trim();
        form.querySelector('[data-link-warning]').classList.toggle('hidden', link === '' || isWebAddress(link));
        clearTimeout(linkTimer);
        linkTimer = setTimeout(() => loadLinkPreview(link), 600);
        renderPreview();
    });

    async function loadLinkPreview(link) {
        const card = form.querySelector('[data-preview-link]');
        const image = form.querySelector('[data-preview-link-image]');
        const fields = {
            site: form.querySelector('[data-preview-link-site]'),
            title: form.querySelector('[data-preview-link-title]'),
            description: form.querySelector('[data-preview-link-description]'),
            status: form.querySelector('[data-preview-link-status]'),
        };

        if (!isWebAddress(link)) {
            return;
        }

        Object.values(fields).forEach((field) => (field.textContent = ''));
        image.classList.add('hidden');
        fields.status.textContent = 'Loading preview…';
        fields.site.textContent = new URL(link).hostname;

        try {
            const response = await fetch(`${card.dataset.previewUrl}?url=${encodeURIComponent(link)}`, {
                headers: { Accept: 'application/json' },
            });

            if (linkInput.value.trim() !== link) {
                return;
            }

            if (!response.ok) {
                fields.status.textContent = 'No preview available. Facebook may still show one after posting (private posts cannot be shared).';
                return;
            }

            const preview = await response.json();
            fields.site.textContent = preview.site_name ?? '';
            fields.title.textContent = preview.title ?? '';
            fields.description.textContent = preview.description ?? '';
            fields.status.textContent = '';

            if (preview.image) {
                image.src = preview.image;
                image.classList.remove('hidden');
            }
        } catch {
            fields.status.textContent = 'Could not load the preview.';
        }
    }

    if (isWebAddress(linkInput.value.trim())) {
        loadLinkPreview(linkInput.value.trim());
    }
    updateCount();

    // --- Media --------------------------------------------------------------

    function showMedia(file) {
        if (mediaUrl) {
            URL.revokeObjectURL(mediaUrl);
        }

        const selected = form.querySelector('[data-media-selected]');
        const thumb = form.querySelector('[data-media-thumb]');

        if (!file) {
            mediaUrl = null;
            mediaKind = null;
            selected.classList.add('hidden');
            selected.classList.remove('flex');
            dropzone.classList.remove('hidden');
            refreshThumbnailField();
            refreshMediaWarning();
            renderPreview();
            return;
        }

        mediaUrl = URL.createObjectURL(file);
        mediaKind = file.type.startsWith('video/') ? 'video' : 'photo';

        thumb.replaceChildren(mediaElement(false, 'size-full object-cover'));
        form.querySelector('[data-media-name]').textContent = file.name;
        form.querySelector('[data-media-info]').textContent = `${mediaKind === 'video' ? 'Video' : 'Photo'} · ${(file.size / 1024 / 1024).toFixed(1)} MB`;
        selected.classList.remove('hidden');
        selected.classList.add('flex');
        dropzone.classList.add('hidden');

        refreshThumbnailField();
        refreshMediaWarning();
        renderPreview();
    }

    // The cover image is only for videos; clear it when the video goes away.
    const thumbnailField = form.querySelector('[data-thumbnail-field]');
    const thumbnailInput = form.querySelector('[data-thumbnail-input]');
    const thumbnailPreview = form.querySelector('[data-thumbnail-preview]');

    function refreshThumbnailField() {
        const isVideo = mediaKind === 'video';
        thumbnailField.classList.toggle('hidden', !isVideo);
        if (!isVideo) {
            thumbnailInput.value = '';
            showThumbnail(null);
        }
    }

    function showThumbnail(file) {
        if (thumbnailPreview.src) {
            URL.revokeObjectURL(thumbnailPreview.src);
            thumbnailPreview.removeAttribute('src');
        }
        thumbnailPreview.classList.toggle('hidden', !file);
        if (file) {
            thumbnailPreview.src = URL.createObjectURL(file);
        }
    }

    thumbnailInput.addEventListener('change', () => showThumbnail(thumbnailInput.files[0]));

    function mediaElement(withControls, className) {
        const element = document.createElement(mediaKind === 'video' ? 'video' : 'img');
        element.src = mediaUrl;
        element.className = className;
        if (mediaKind === 'video') {
            element.muted = true;
            element.playsInline = true;
            element.controls = withControls;
        }
        return element;
    }

    function refreshMediaWarning() {
        const warning = form.querySelector('[data-media-warning]');
        const platforms = selectedPlatforms();
        const file = mediaInput.files[0];
        const messages = [];

        if (file && mediaKind === 'photo' && platforms.includes('youtube')) {
            messages.push('YouTube only accepts videos.');
        }
        if (file && file.type === 'image/png' && platforms.includes('instagram')) {
            messages.push('Instagram only accepts JPG photos.');
        }
        const importsVideo = form.querySelector('[data-import-input]')?.value.trim() !== '';

        if (!file && !importsVideo && platforms.some((platform) => platform !== 'facebook')) {
            messages.push('Instagram, YouTube and TikTok need a photo or video.');
        }

        warning.textContent = messages.join(' ');
        warning.classList.toggle('hidden', messages.length === 0);
    }

    mediaInput.addEventListener('change', () => showMedia(mediaInput.files[0]));

    form.querySelector('[data-media-remove]').addEventListener('click', () => {
        mediaInput.value = '';
        showMedia(null);
    });

    ['dragenter', 'dragover'].forEach((type) =>
        dropzone.addEventListener(type, (event) => {
            event.preventDefault();
            dropzone.classList.add('border-indigo-500', 'bg-indigo-50');
        }),
    );
    ['dragleave', 'drop'].forEach((type) =>
        dropzone.addEventListener(type, () => dropzone.classList.remove('border-indigo-500', 'bg-indigo-50')),
    );
    dropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        const file = event.dataTransfer.files[0];
        if (!file) {
            return;
        }
        const transfer = new DataTransfer();
        transfer.items.add(file);
        mediaInput.files = transfer.files;
        showMedia(file);
    });

    // --- Preview ------------------------------------------------------------

    function refreshTabs() {
        const platforms = selectedPlatforms();

        if (!platforms.includes(previewPlatform)) {
            previewPlatform = platforms[0] ?? null;
        }

        tabs.replaceChildren(
            ...platforms.map((platform) => {
                const tab = document.createElement('button');
                tab.type = 'button';
                tab.textContent = PLATFORM_LABELS[platform];
                tab.className =
                    'rounded-full px-2.5 py-1 ' +
                    (platform === previewPlatform ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200');
                tab.addEventListener('click', () => {
                    previewPlatform = platform;
                    refreshTabs();
                    renderPreview();
                });
                return tab;
            }),
        );
    }

    function renderPreview() {
        const platform = previewPlatform ?? 'facebook';
        const account = selectedAccounts().find((box) => box.dataset.platform === platform);
        const custom = form.querySelector(`[data-platform-caption="${platform}"]`)?.value.trim();
        const text = custom || caption.value;

        form.querySelector('[data-preview-name]').textContent = account?.dataset.name ?? 'Your Page';
        form.querySelector('[data-preview-meta]').textContent =
            `${PLATFORM_LABELS[platform]} · ${form.querySelector('[data-when][value="later"]').checked ? 'Scheduled' : 'Just now'}`;

        const avatar = form.querySelector('[data-preview-avatar]');
        avatar.replaceChildren();
        if (account?.dataset.avatar) {
            const image = document.createElement('img');
            image.src = account.dataset.avatar;
            image.className = 'size-full object-cover';
            avatar.append(image);
        }

        const captionElement = form.querySelector('[data-preview-caption]');
        captionElement.textContent = text;
        captionElement.classList.toggle('hidden', text.trim() === '');

        const link = form.querySelector('[data-link-input]').value.trim();
        const showLink = isWebAddress(link) && platform === 'facebook' && !mediaUrl;
        form.querySelector('[data-preview-link]').classList.toggle('hidden', !showLink);

        const media = form.querySelector('[data-preview-media]');
        const placeholder = form.querySelector('[data-preview-placeholder]');
        media.replaceChildren();

        if (mediaUrl) {
            const tall = platform === 'tiktok' || (platform === 'instagram' && mediaKind === 'video');
            media.append(mediaElement(true, `mx-auto w-full ${tall ? 'aspect-[9/16]' : 'max-h-[28rem]'} object-contain`));
        }
        const importsVideo = form.querySelector('[data-import-input]')?.value.trim() !== '';
        placeholder.textContent = importsVideo && !mediaUrl ? '🎬 The video will be downloaded from the link' : placeholder.dataset.defaultText ?? placeholder.textContent;
        media.classList.toggle('hidden', !mediaUrl);
        placeholder.classList.toggle('hidden', Boolean(mediaUrl) || showLink);

        const accounts = selectedAccounts().length;
        form.querySelector('[data-preview-note]').textContent = accounts
            ? `Will be published to ${accounts} account${accounts === 1 ? '' : 's'}.`
            : 'Select accounts to see how the post will look.';
    }

    // --- Schedule and submit ------------------------------------------------

    form.querySelectorAll('[data-when]').forEach((radio) => {
        radio.addEventListener('change', () => {
            const isLater = radio.value === 'later' && radio.checked;
            scheduleFields.classList.toggle('hidden', !isLater);
            if (!isLater) {
                scheduleFields.querySelector('input').value = '';
            }
            renderPreview();
        });
    });

    form.addEventListener('submit', () => {
        form.querySelector('[data-submit]').disabled = true;
        form.querySelector('[data-upload-status]').classList.toggle('hidden', !mediaInput.files[0]);
    });

    refreshAccounts();
}

document.querySelectorAll('[data-open-all]').forEach((openAllButton) => {
    openAllButton.addEventListener('click', () => {
        let blocked = 0;

        JSON.parse(openAllButton.dataset.openAll).forEach((link) => {
            const tab = window.open(link, '_blank');

            if (tab) {
                tab.opener = null;
            } else {
                blocked++;
            }
        });

        document.querySelector('[data-popup-warning]').classList.toggle('hidden', blocked === 0);
    });
});

document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        await navigator.clipboard.writeText(button.dataset.copy);
        const label = button.textContent;
        button.textContent = 'Copied ✓';
        setTimeout(() => (button.textContent = label), 1500);
    });
});

document.querySelectorAll('[data-reveal]').forEach((button) => {
    button.addEventListener('click', () => {
        const secret = button.parentElement.querySelector('[data-secret]');
        const hidden = secret.textContent.startsWith('•');
        secret.textContent = hidden ? secret.dataset.secret : '••••••••••••••••';
        button.textContent = hidden ? 'Hide' : 'Show';
    });
});

const liveForm = document.querySelector('[data-live-form]');

if (liveForm) {
    const pages = [...liveForm.querySelectorAll('[data-live-page]')];
    const mainSelect = liveForm.querySelector('[data-live-main]');
    const shareFields = liveForm.querySelector('[data-live-share-fields]');

    const refreshMainOptions = () => {
        const previous = mainSelect.value || mainSelect.dataset.old;
        mainSelect.replaceChildren(
            ...pages
                .filter((page) => page.checked)
                .map((page) => {
                    const option = new Option(page.dataset.name, page.value);
                    option.selected = page.value === previous;
                    return option;
                }),
        );
    };

    pages.forEach((page) => page.addEventListener('change', refreshMainOptions));
    liveForm.querySelectorAll('[data-live-mode]').forEach((radio) =>
        radio.addEventListener('change', () => shareFields.classList.toggle('hidden', !(radio.checked && radio.value === 'share'))),
    );
    refreshMainOptions();
}

function isWebAddress(value) {
    try {
        const url = new URL(value);
        return url.protocol === 'https:' || url.protocol === 'http:';
    } catch {
        return false;
    }
}

const sidebar = document.querySelector('[data-sidebar]');

if (sidebar) {
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const toggleSidebar = (open) => {
        sidebar.classList.toggle('-translate-x-full', !open);
        backdrop.classList.toggle('hidden', !open);
    };

    document.querySelector('[data-sidebar-open]')?.addEventListener('click', () => toggleSidebar(true));
    document.querySelector('[data-sidebar-close]')?.addEventListener('click', () => toggleSidebar(false));
    backdrop.addEventListener('click', () => toggleSidebar(false));
}
