// A photo made upload-sized in the browser before it's sent: scaled so
// its longer side is at most `maxSide` and re-saved as JPEG. Phone and
// camera photos run 3-10 MB -- more than many servers accept, and far more
// than an avatar or bio photo (kept at 400-800px) needs. Anything that
// isn't a decodable image (HEIC in some browsers, say), or is already
// small, goes up as it is.
export async function shrinkImage(file, { maxSide = 1600, quality = 0.88 } = {}) {
    if (!file?.type?.startsWith('image/') || file.type === 'image/gif' || file.type === 'image/svg+xml') return file;

    let bitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return file;
    }

    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    if (scale === 1 && file.size < 1.5 * 1024 * 1024) {
        bitmap.close();
        return file;
    }

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const context = canvas.getContext('2d');
    // JPEG has no transparency: put a PNG's clear areas on white.
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    if (!blob) return file;

    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
}
