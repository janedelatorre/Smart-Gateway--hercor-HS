/**
 * =====================================================================
 * STUDENTS MODULE - front-end logic
 * Handles: AJAX table load, search/filter, pagination, add/edit modal,
 * webcam capture, and face-api.js descriptor extraction (used ONLY as
 * backup biometric data - primary auth remains the barcode).
 * =====================================================================
 */
const API_STUDENT = '../api/student.php';
let currentPage = 1;
let stream = null;
let faceModelsLoaded = false;

// ---- Load students list (AJAX) ----
function loadStudents(page = 1) {
    currentPage = page;
    const search = document.getElementById('searchStudent').value;
    const grade = document.getElementById('filterGrade').value;
    const status = document.getElementById('filterStatus').value;

    sgGet(`${API_STUDENT}?action=list&page=${page}&search=${encodeURIComponent(search)}&grade=${encodeURIComponent(grade)}&status=${encodeURIComponent(status)}`)
        .then(res => {
            if (!res.success) { sgToast('error', res.message || 'Failed to load students'); return; }
            renderStudentsTable(res.data);
            renderPagination(res.total, res.page, res.per_page);
            document.getElementById('studentsSummary').textContent =
                `Showing ${res.data.length ? ((res.page - 1) * res.per_page + 1) : 0} to ${(res.page - 1) * res.per_page + res.data.length} of ${res.total} entries`;
            populateGradeFilter(res.grades || []);
        });
}

function populateGradeFilter(grades) {
    const sel = document.getElementById('filterGrade');
    if (sel.dataset.loaded) return;
    grades.forEach(g => {
        const opt = document.createElement('option');
        opt.value = g; opt.textContent = g;
        sel.appendChild(opt);
    });
    sel.dataset.loaded = '1';
}

function renderStudentsTable(students) {
    const tbody = document.getElementById('studentsTableBody');
    if (!students.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">No students found.</td></tr>`;
        return;
    }
    tbody.innerHTML = students.map(s => `
        <tr>
            <td><div class="avatar-circle">${s.photo ? `<img src="../uploads/student/${s.photo}">` : '<i class="bi bi-person-fill"></i>'}</div></td>
            <td>${s.student_id}</td>
            <td>${s.fullname}</td>
            <td>${s.grade}</td>
            <td>${s.contact_number ?? '-'}</td>
            <td>${s.status === 'Active' ? '<span class="badge-match">Active</span>' : '<span class="badge-denied">Inactive</span>'}</td>
            <td>
                <button class="btn btn-sm btn-light" onclick='openEditStudent(${JSON.stringify(s)})' title="Edit"><i class="bi bi-pencil-fill text-primary"></i></button>
                <button class="btn btn-sm btn-light" onclick="deleteStudent(${s.id})" title="Delete"><i class="bi bi-trash-fill text-danger"></i></button>
            </td>
        </tr>
    `).join('');
}

function renderPagination(total, page, perPage) {
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const ul = document.getElementById('studentsPagination');
    let html = '';
    for (let i = 1; i <= totalPages; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" onclick="loadStudents(${i});return false;">${i}</a></li>`;
    }
    ul.innerHTML = html;
}

// ---- Search / filter listeners ----
document.getElementById('searchStudent').addEventListener('input', debounce(() => loadStudents(1), 350));
document.getElementById('filterGrade').addEventListener('change', () => loadStudents(1));
document.getElementById('filterStatus').addEventListener('change', () => loadStudents(1));

function debounce(fn, delay) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
}

// ---- Add / Edit modal handling ----
function openAddStudent() {
    document.getElementById('studentModalTitle').textContent = 'Add New Student';
    document.getElementById('studentForm').reset();
    document.getElementById('studentDbId').value = '';
    document.getElementById('photoDataInput').value = '';
    document.getElementById('studentIdInput').disabled = false;
    resetCaptureBox();
}

function openEditStudent(student) {
    document.getElementById('studentModalTitle').textContent = 'Edit Student';
    const form = document.getElementById('studentForm');
    form.reset();
    document.getElementById('studentDbId').value = student.id;
    form.student_id.value = student.student_id;
    form.fullname.value = student.fullname;
    form.grade.value = student.grade;
    form.section.value = student.section || '';
    form.contact_number.value = student.contact_number || '';
    form.guardian_name.value = student.guardian_name || '';
    form.email.value = student.email || '';
    form.status.value = student.status;
    resetCaptureBox();
    if (student.photo) {
        document.getElementById('capturedPhoto').src = `../uploads/student/${student.photo}`;
        document.getElementById('capturedPhoto').classList.remove('d-none');
        document.getElementById('capturePlaceholder').classList.add('d-none');
    }
    new bootstrap.Modal(document.getElementById('studentModal')).show();
}

function resetCaptureBox() {
    document.getElementById('capturedPhoto').classList.add('d-none');
    document.getElementById('video').classList.add('d-none');
    document.getElementById('capturePlaceholder').classList.remove('d-none');
    document.getElementById('startCameraBtn').classList.remove('d-none');
    document.getElementById('captureBtn').classList.add('d-none');
    document.getElementById('retakeBtn').classList.add('d-none');
    document.getElementById('photoDataInput').value = '';
    stopCamera();
}

// ---- Webcam capture (WebcamJS-style using native getUserMedia) ----
document.getElementById('startCameraBtn').addEventListener('click', async () => {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: true });
        const video = document.getElementById('video');
        video.srcObject = stream;
        video.classList.remove('d-none');
        document.getElementById('capturePlaceholder').classList.add('d-none');
        document.getElementById('startCameraBtn').classList.add('d-none');
        document.getElementById('captureBtn').classList.remove('d-none');

        if (!faceModelsLoaded) {
            // Load face-api.js models lazily (tiny face detector) for backup face descriptor
            await faceapi.nets.tinyFaceDetector.loadFromUri('https://justadudewhohacks.github.io/face-api.js/models');
            await faceapi.nets.faceLandmark68Net.loadFromUri('https://justadudewhohacks.github.io/face-api.js/models');
            await faceapi.nets.faceRecognitionNet.loadFromUri('https://justadudewhohacks.github.io/face-api.js/models');
            faceModelsLoaded = true;
        }
    } catch (err) {
        sgToast('error', 'Camera access denied or unavailable');
    }
});

document.getElementById('captureBtn').addEventListener('click', async () => {
    const video = document.getElementById('video');
    const canvas = document.getElementById('captureCanvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.9);

    document.getElementById('capturedPhoto').src = dataUrl;
    document.getElementById('capturedPhoto').classList.remove('d-none');
    video.classList.add('d-none');
    document.getElementById('captureBtn').classList.add('d-none');
    document.getElementById('retakeBtn').classList.remove('d-none');
    document.getElementById('photoDataInput').value = dataUrl;

    // Extract face descriptor for future backup verification (best-effort)
    try {
        const detection = await faceapi.detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks().withFaceDescriptor();
        if (detection) {
            document.getElementById('studentForm').dataset.faceDescriptor = JSON.stringify(Array.from(detection.descriptor));
        }
    } catch (e) { /* Non-blocking: facial data is a backup feature only */ }

    stopCamera();
});

document.getElementById('retakeBtn').addEventListener('click', () => {
    resetCaptureBox();
    document.getElementById('startCameraBtn').click();
});

function stopCamera() {
    if (stream) {
        stream.getTracks().forEach(t => t.stop());
        stream = null;
    }
}

// ---- Save (Add/Edit) via AJAX ----
document.getElementById('studentForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', document.getElementById('studentDbId').value ? 'update' : 'create');
    formData.append('face_descriptor', this.dataset.faceDescriptor || '');

    sgPost(API_STUDENT, formData).then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('studentModal')).hide();
            sgToast('success', res.message || 'Student saved successfully');
            loadStudents(currentPage);
        } else {
            sgToast('error', res.message || 'Failed to save student');
        }
    });
});

// ---- Delete ----
function deleteStudent(id) {
    confirmDelete('This student record will be permanently removed.', () => {
        sgPost(API_STUDENT, { action: 'delete', id: id, csrf_token: document.querySelector('[name=csrf_token]').value })
            .then(res => {
                if (res.success) {
                    sgToast('success', 'Student deleted');
                    loadStudents(currentPage);
                } else {
                    sgToast('error', res.message || 'Failed to delete student');
                }
            });
    });
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', () => loadStudents(1));
