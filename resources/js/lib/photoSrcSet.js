// The srcset for an avatar or bio photo: the server keeps a small copy of
// each (AvatarProcessor) behind ?size=sm, so the browser fetches the full
// one only where the photo shows big enough on screen to need it.
export function photoSrcSet(url, smallWidth, fullWidth) {
    const small = url + (url.includes('?') ? '&' : '?') + 'size=sm';
    return `${small} ${smallWidth}w, ${url} ${fullWidth}w`;
}
