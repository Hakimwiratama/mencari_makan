
const tempatMakan = [
    {
        nama: "Rumah Makan Padang",
        menu: "Rendang, ayam pop, gulai",
        alamat: "Kota Padang, Sumatera Barat"
    },
    {
        nama: "Warung Sate",
        menu: "Sate Padang, lontong",
        alamat: "Kota Padang, Sumatera Barat"
    },
    {
        nama: "Kedai Nasi Goreng",
        menu: "Nasi goreng, mi goreng",
        alamat: "Kota Padang, Sumatera Barat"
    },
    {
        nama: "Kedai Es Durian",
        menu: "Es durian, jus buah",
        alamat: "Kota Padang, Sumatera Barat"
    }
];

function escapeHTML(teks) {
    return String(teks).replace(/[&<>"']/g, karakter => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
    })[karakter]);
}

function cariMakanan() {
    const input = document.getElementById("kataKunci");
    const hasil = document.getElementById("hasil");
    const kataKunci = input.value.toLowerCase().trim();

    if (!kataKunci) {
        hasil.innerHTML = "<p>Silakan masukkan nama makanan atau tempat makan.</p>";
        input.focus();
        return;
    }

    const ditemukan = tempatMakan.filter(tempat =>
        (
            tempat.nama + " " +
            tempat.menu + " " +
            tempat.alamat
        ).toLowerCase().includes(kataKunci)
    );

    if (ditemukan.length === 0) {
        hasil.innerHTML = "<p>Tempat makan tidak ditemukan. Coba kata kunci lain.</p>";
        return;
    }

    hasil.innerHTML = ditemukan.map(tempat => {
        const alamatMaps = encodeURIComponent(
            `${tempat.nama}, ${tempat.alamat}`
        );

        return `
            <article class="kartu">
                <h3>${escapeHTML(tempat.nama)}</h3>
                <p><strong>Menu:</strong> ${escapeHTML(tempat.menu)}</p>
                <p><strong>Lokasi:</strong> ${escapeHTML(tempat.alamat)}</p>
                <a
                    href="https://www.google.com/maps/search/?api=1&query=${alamatMaps}"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Lihat di Google Maps ↗
                </a>
            </article>
        `;
    }).join("");
}

// Pencarian dengan tombol Enter
document.addEventListener("DOMContentLoaded", () => {
    const input = document.getElementById("kataKunci");

    input.addEventListener("keydown", event => {
        if (event.key === "Enter") {
            cariMakanan();
        }
    });
});
