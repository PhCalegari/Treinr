// Prepara a foto antes do envio: reduz (lado maior até 1600 px) e regrava em JPEG.
// Regravar no canvas aplica a rotação da câmera e descarta os metadados (EXIF),
// inclusive a localização. Se o navegador não conseguir ler o formato, envia o original
// e o servidor responde com uma mensagem.
export async function prepareImage(file: File, maxSide = 1600, quality = 0.85): Promise<File> {
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const ctx = canvas.getContext('2d');
        if (!ctx) return file;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();
        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (!blob) return file;
        return new File([blob], `${file.name.replace(/\.[^.]+$/, '') || 'foto'}.jpg`, { type: 'image/jpeg' });
    } catch {
        return file;
    }
}
