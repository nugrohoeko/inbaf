<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { state } from '../store';
import Icon from './Icon.vue';

const isId = computed(() => state.language === 'id');
const skillIndex = ref(0);
const levelIndex = ref(0);
const skills = computed(() => Array.isArray(state.competencies) ? state.competencies : []);
const levels = computed(() => Array.isArray(state.levels) ? state.levels : []);
const selectedSkill = computed(() => skills.value[skillIndex.value] || skills.value[0]);
const selectedLevel = computed(() => levels.value[levelIndex.value] || levels.value[0]);
watch(() => skills.value.length, length => { if (skillIndex.value >= length) skillIndex.value = 0; });
watch(() => levels.value.length, length => { if (levelIndex.value >= length) levelIndex.value = 0; });

const skillContent = {
    listening: {
        description: 'Understand conversations, announcements, and spoken instructions.',
        original: 'Memahami percakapan, pengumuman, dan instruksi lisan.',
        situation: ['Saat dunia berbicara, tangkap maknanya.', 'When the world speaks, find the meaning.'],
        context: ['Simak pengumuman di kampus, ikuti petunjuk perjalanan, atau pahami inti percakapan.', 'Follow a campus announcement, understand travel directions, or catch the point of a conversation.'],
        sample: ['“Kereta tujuan Bandung berangkat dari jalur dua.”', '“Kereta tujuan Bandung berangkat dari jalur dua.”'],
        meaning: ['Pahami informasi penting di balik apa yang Anda dengar.', 'Find the important information in what you hear.'],
    },
    grammar: {
        description: 'Use sentence structures and word forms accurately.',
        original: 'Menggunakan struktur kalimat dan bentuk kata secara tepat.',
        situation: ['Beri struktur pada setiap gagasan.', 'Give every thought a clear structure.'],
        context: ['Hubungkan waktu, tindakan, dan maksud dengan susunan kalimat yang sesuai.', 'Connect time, action, and intention through sentences that make sense.'],
        sample: ['“Saya sudah mengirim tugas itu kemarin.”', '“Saya sudah mengirim tugas itu kemarin.”'],
        meaning: ['Perhatikan bagaimana “sudah” memberi konteks pada tindakan.', 'Notice how “sudah” gives the action its context.'],
    },
    reading: {
        description: 'Find information, main ideas, and meaning in written texts.',
        original: 'Menemukan informasi, gagasan utama, dan makna dalam teks.',
        situation: ['Baca kata-katanya. Temukan gagasannya.', 'Read the words. Discover the ideas.'],
        context: ['Temukan informasi dalam jadwal, pahami pesan tertulis, dan ikuti alur sebuah bacaan.', 'Find information in a timetable, understand a written message, and follow the thread of a text.'],
        sample: ['“Perpustakaan buka hingga pukul delapan malam.”', '“Perpustakaan buka hingga pukul delapan malam.”'],
        meaning: ['Hubungkan informasi tertulis dengan hal yang perlu Anda ketahui.', 'Connect the written information with what you need to know.'],
    },
    vocabulary: {
        description: 'Understand the meaning and use of words in context.',
        original: 'Memahami makna dan penggunaan kata dalam konteks.',
        situation: ['Pilih kata yang membuka percakapan.', 'Find the word that opens a conversation.'],
        context: ['Kenali makna kata, perbedaan ungkapan, dan pilihan bahasa yang cocok dengan situasinya.', 'Recognise word meanings, differences in expression, and language that fits the situation.'],
        sample: ['“Boleh saya meminjam buku ini?”', '“Boleh saya meminjam buku ini?”'],
        meaning: ['Meminjam, memberi, mengembalikan: konteks membuat pilihan kata berarti.', 'Borrowing, giving, returning: context makes the choice of words matter.'],
    },
    writing: {
        description: 'Create clear, coherent writing that fits its purpose.',
        original: 'Menyusun tulisan yang jelas, runtut, dan sesuai tujuan.',
        situation: ['Jadikan gagasan Anda mudah dipahami.', 'Make your ideas easy to understand.'],
        context: ['Sampaikan pesan, susun pendapat, dan sesuaikan tulisan dengan pembaca serta tujuannya.', 'Write a message, organise an opinion, and adapt your writing to its reader and purpose.'],
        sample: ['“Yth. Ibu Rina, saya ingin menanyakan jadwal konsultasi.”', '“Yth. Ibu Rina, saya ingin menanyakan jadwal konsultasi.”'],
        meaning: ['Tujuan dan pembaca membantu menentukan bentuk tulisan.', 'Purpose and audience help shape the way you write.'],
    },
    speaking: {
        description: 'Express spoken ideas clearly and relevantly.',
        original: 'Menyampaikan gagasan lisan dengan jelas dan relevan.',
        situation: ['Beri suara pada sudut pandang Anda.', 'Give your perspective a voice.'],
        context: ['Perkenalkan diri, jelaskan pengalaman, atau sampaikan pendapat melalui respons lisan.', 'Introduce yourself, describe an experience, or share an opinion in a spoken response.'],
        sample: ['“Menurut saya, belajar bersama membantu kita saling memahami.”', '“Menurut saya, belajar bersama membantu kita saling memahami.”'],
        meaning: ['Latih penyampaian gagasan yang dapat diikuti pendengar.', 'Practise expressing ideas that a listener can follow.'],
    },
    communication: {
        description: 'Use language in social and professional situations.',
        original: 'Menggunakan bahasa dalam situasi sosial dan profesional.',
        situation: ['Temukan bahasa yang sesuai momennya.', 'Meet the moment with the right language.'],
        context: ['Pahami hubungan antarpenutur, tanggapi maksud percakapan, dan pilih ungkapan yang sesuai.', 'Consider who you are speaking with, respond to their intention, and choose an appropriate expression.'],
        sample: ['“Permisi, apakah kursi ini masih kosong?”', '“Permisi, apakah kursi ini masih kosong?”'],
        meaning: ['Satu situasi, satu maksud, dan pilihan bahasa yang tepat.', 'A situation, an intention, and a thoughtful choice of words.'],
    },
};

const levelContent = {
    A1: { original: 'Memahami ungkapan sangat sederhana.', description: 'Understand very simple expressions.', name: 'Pemula', context: ['Mulai dari perkenalan, sapaan, dan informasi pribadi yang sederhana.', 'Begin with introductions, greetings, and simple personal information.'], example: '“Nama saya Alex. Saya tinggal di Jakarta.”' },
    A2: { original: 'Berkomunikasi dalam situasi rutin sederhana.', description: 'Communicate in simple, routine situations.', name: 'Dasar', context: ['Bangun percakapan tentang kebutuhan sehari-hari dan kegiatan yang akrab.', 'Build conversations around everyday needs and familiar activities.'], example: '“Saya ingin membeli dua tiket untuk besok pagi.”' },
    B1: { original: 'Berkomunikasi tentang kehidupan sehari-hari.', description: 'Communicate about everyday life.', name: 'Menengah', context: ['Ceritakan pengalaman dan jelaskan alasan dalam topik sehari-hari.', 'Describe experiences and explain your reasons on everyday topics.'], example: '“Saya memilih kota ini karena ingin mempelajari budayanya.”' },
    B2: { original: 'Berkomunikasi mandiri dalam konteks yang lebih kompleks.', description: 'Communicate independently in more complex contexts.', name: 'Menengah atas', context: ['Kembangkan pendapat dan hubungkan gagasan dalam percakapan yang lebih kompleks.', 'Develop opinions and connect ideas in more complex conversations.'], example: '“Ada beberapa pertimbangan yang perlu kita bahas sebelum mengambil keputusan.”' },
    C1: { original: 'Menggunakan bahasa dalam konteks profesional dan akademik.', description: 'Use language in professional and academic contexts.', name: 'Mahir', context: ['Susun argumen yang runtut dan sesuaikan bahasa untuk konteks akademik atau profesional.', 'Build a coherent argument and adapt language to an academic or professional setting.'], example: '“Temuan ini menunjukkan perlunya pendekatan yang lebih menyeluruh.”' },
    C2: { original: 'Memahami dan menggunakan bahasa dengan kemahiran sangat tinggi.', description: 'Understand and use language with very high proficiency.', name: 'Sangat mahir', context: ['Jelajahi nuansa makna dan ungkapkan gagasan rumit dengan pilihan bahasa yang cermat.', 'Explore nuances of meaning and express complex ideas with carefully chosen language.'], example: '“Perbedaan penafsiran tersebut mencerminkan keragaman perspektif yang mendasarinya.”' },
};

const activeSkillContent = computed(() => skillContent[String(selectedSkill.value?.code || '').toLowerCase()]);
const activeLevelContent = computed(() => levelContent[String(selectedLevel.value?.code || '').toUpperCase()]);
const localized = pair => pair?.[isId.value ? 0 : 1] || '';
const skillName = skill => isId.value ? (skill.name || skill.name_en || skill.code) : (skill.name_en || skill.name || skill.code);
const levelName = level => {
    const known = levelContent[String(level.code || '').toUpperCase()];
    const defaultNames = { A1: 'Beginner', A2: 'Elementary', B1: 'Intermediate', B2: 'Upper Intermediate', C1: 'Advanced', C2: 'Proficient' };
    return isId.value && known && level.name === defaultNames[level.code] ? known.name : (level.name || level.code);
};
function skillDescription(skill) {
    if (!isId.value && skill.description_en) return skill.description_en;
    const known = skillContent[String(skill.code || '').toLowerCase()];
    return !isId.value && known && skill.description === known.original ? known.description : skill.description;
}
function levelDescription(level) {
    if (!isId.value && level.description_en) return level.description_en;
    const known = levelContent[String(level.code || '').toUpperCase()];
    const suffix = ' Klasifikasi internal INBAF; belum merupakan kesetaraan CEFR resmi.';
    if (known && (level.description === known.original || level.description === known.original + suffix)) return isId.value ? known.original : known.description;
    return level.description;
}
function navigate(event, index, total, kind) {
    const offsets = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
    if (!(event.key in offsets) && event.key !== 'Home' && event.key !== 'End') return;
    event.preventDefault();
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? total - 1 : (index + offsets[event.key] + total) % total;
    if (kind === 'skill') skillIndex.value = next;
    else levelIndex.value = next;
    const group = event.currentTarget.parentElement;
    nextTick(() => group?.querySelectorAll('button')[next]?.focus());
}
</script>

<template>
    <section id="competencies" class="atlas-section atlas-competencies" :class="{ 'atlas-dark': state.dark }" aria-labelledby="atlas-skills-heading">
        <div class="atlas-inner">
            <header class="atlas-heading">
                <span class="atlas-eyebrow"><span aria-hidden="true"></span>{{ isId ? 'BAHASA, DARI BERBAGAI SISI' : 'LANGUAGE, IN EVERY DIMENSION' }}</span>
                <div class="atlas-heading-row">
                    <h2 id="atlas-skills-heading">{{ isId ? 'Satu bahasa.' : 'One language.' }}<br><span>{{ isId ? 'Banyak cara terhubung.' : 'So many ways to connect.' }}</span></h2>
                    <p>{{ isId ? 'Dari mendengar hingga menyampaikan gagasan, jelajahi keterampilan yang membentuk komunikasi. Pilih satu untuk melihatnya dalam kehidupan nyata.' : 'From listening to expressing an idea, explore the skills that bring communication to life. Choose one to see it in the everyday.' }}</p>
                </div>
            </header>

            <div v-if="skills.length" class="atlas-signal">
                <div class="atlas-skill-navigation" role="group" :aria-label="isId ? 'Jelajahi kompetensi bahasa' : 'Explore language competencies'">
                    <button v-for="(skill, index) in skills" :key="skill.id || skill.code" type="button" class="atlas-skill-button" :class="{ 'is-selected': skillIndex === index }" :aria-pressed="skillIndex === index" aria-controls="atlas-skill-detail" @click="skillIndex = index" @keydown="navigate($event, index, skills.length, 'skill')">
                        <span class="atlas-skill-index">{{ String(index + 1).padStart(2, '0') }}</span>
                        <span class="atlas-skill-icon"><Icon :name="skill.icon || 'Languages'" :size="25" /></span>
                        <span class="atlas-skill-name">{{ skillName(skill) }}</span>
                        <Icon class="atlas-skill-arrow" name="ArrowUpRight" :size="19" />
                    </button>
                </div>

                <div v-if="selectedSkill" id="atlas-skill-detail" class="atlas-skill-detail" role="region" aria-live="polite" aria-labelledby="atlas-skill-title">
                    <div class="atlas-skill-main">
                        <span class="atlas-detail-label"><Icon :name="selectedSkill.icon || 'Languages'" :size="23" />{{ skillName(selectedSkill) }}</span>
                        <h3 id="atlas-skill-title">{{ activeSkillContent ? localized(activeSkillContent.situation) : skillName(selectedSkill) }}</h3>
                        <p>{{ skillDescription(selectedSkill) }}</p>
                        <p v-if="activeSkillContent" class="atlas-skill-context">{{ localized(activeSkillContent.context) }}</p>
                    </div>
                    <div v-if="activeSkillContent" class="atlas-language-note">
                        <span class="atlas-note-label">{{ isId ? 'DALAM KEHIDUPAN NYATA' : 'IN REAL LIFE' }}<Icon name="ArrowDown" :size="18" /></span>
                        <blockquote lang="id">{{ localized(activeSkillContent.sample) }}</blockquote>
                        <p>{{ localized(activeSkillContent.meaning) }}</p>
                        <span class="atlas-example-note">{{ isId ? 'Contoh penggunaan bahasa, bukan soal ujian.' : 'An example of language in use, not an assessment question.' }}</span>
                    </div>
                </div>
            </div>
            <p v-else class="atlas-empty">{{ isId ? 'Kompetensi yang tersedia akan ditampilkan di sini.' : 'Available assessment competencies will appear here.' }}</p>
            <p class="atlas-footnote"><Icon name="Layers" :size="19" />{{ isId ? 'Setiap paket memiliki susunan kompetensi tersendiri. Periksa rinciannya sebelum memulai.' : 'Each assessment has its own mix of competencies. Review its sections before you begin.' }}</p>
        </div>
    </section>

    <section id="proficiency" class="atlas-section atlas-proficiency" :class="{ 'atlas-dark': state.dark }" aria-labelledby="atlas-levels-heading">
        <div class="atlas-inner">
            <header class="atlas-heading">
                <span class="atlas-eyebrow"><span aria-hidden="true"></span>{{ isId ? 'RUANG UNTUK TERUS TUMBUH' : 'ROOM TO KEEP GROWING' }}</span>
                <div class="atlas-heading-row">
                    <h2 id="atlas-levels-heading">{{ isId ? 'Setiap langkah' : 'Every step' }}<br><span>{{ isId ? 'membuka kemungkinan.' : 'opens a possibility.' }}</span></h2>
                    <p>{{ isId ? 'Kenali rentang kemahiran INBAF, dari ungkapan pertama hingga gagasan yang lebih kompleks. Jelajahi level untuk memahami arahnya.' : 'Discover the INBAF proficiency range, from your first expressions to more complex ideas. Explore a level to understand the direction.' }}</p>
                </div>
            </header>

            <div v-if="levels.length" class="atlas-pathway">
                <div class="atlas-pathway-caption"><span>{{ isId ? 'MULAI DARI DASAR' : 'BEGIN WITH THE BASICS' }}</span><Icon name="ArrowRight" :size="23" /><span>{{ isId ? 'KEMBANGKAN KEMAHIRAN' : 'EXPAND YOUR EXPRESSION' }}</span></div>
                <div class="atlas-level-navigation" role="group" :aria-label="isId ? 'Jelajahi level kemahiran' : 'Explore proficiency levels'">
                    <button v-for="(level, index) in levels" :key="level.id || level.code" type="button" class="atlas-level-button" :class="{ 'is-selected': levelIndex === index }" :style="{ '--atlas-step': Math.min(index, 5) }" :aria-pressed="levelIndex === index" aria-controls="atlas-level-detail" @click="levelIndex = index" @keydown="navigate($event, index, levels.length, 'level')">
                        <span class="atlas-level-code">{{ level.code }}</span>
                        <span class="atlas-level-name">{{ levelName(level) }}</span>
                        <span class="atlas-level-select"><span>{{ isId ? 'Jelajahi' : 'Explore' }}</span><Icon name="ArrowUpRight" :size="18" /></span>
                    </button>
                </div>

                <div v-if="selectedLevel" id="atlas-level-detail" class="atlas-level-detail" role="region" aria-live="polite" aria-labelledby="atlas-level-title">
                    <div class="atlas-level-summary"><span class="atlas-selected-code">{{ selectedLevel.code }}</span><div><span class="atlas-detail-label">{{ isId ? 'GAMBARAN KEMAHIRAN' : 'A LOOK AT THE LEVEL' }}</span><h3 id="atlas-level-title">{{ levelName(selectedLevel) }}</h3><p>{{ levelDescription(selectedLevel) }}</p></div></div>
                    <div v-if="activeLevelContent" class="atlas-level-context"><p>{{ localized(activeLevelContent.context) }}</p><span class="atlas-note-label">{{ isId ? 'CONTOH UNGKAPAN' : 'AN EXAMPLE EXPRESSION' }}</span><blockquote lang="id">{{ activeLevelContent.example }}</blockquote></div>
                </div>
            </div>
            <p v-else class="atlas-empty">{{ isId ? 'Level yang dikonfigurasi penyelenggara akan ditampilkan di sini.' : 'Levels configured by the assessment institution will appear here.' }}</p>
            <p class="atlas-footnote atlas-classification-note"><Icon name="CircleHelp" :size="19" />{{ isId ? 'Level merupakan klasifikasi internal INBAF, bukan kesetaraan resmi CEFR. Contoh bersifat ilustratif; hasil Anda mengikuti aturan penilaian paket ujian.' : 'Levels are internal INBAF classifications, not official CEFR equivalence. Examples are illustrative; your result follows the assessment’s scoring rules.' }}</p>
        </div>
    </section>
</template>

<style scoped>
.atlas-section{--atlas-ink:var(--lp-ink,#102e29);--atlas-muted:var(--lp-muted,#546a62);--atlas-line:var(--lp-line,#dce5de);--atlas-paper:var(--lp-paper,#f7f9f2);--atlas-mint:var(--lp-mint,#c2fa85);--atlas-deep:var(--lp-deep,#11382f);position:relative;min-width:0;padding:clamp(4rem,7vw,7rem) clamp(1rem,4vw,3rem);color:var(--atlas-ink);background:var(--atlas-paper);scroll-margin-top:8rem;font-family:inherit}
.atlas-section *{min-width:0}.atlas-inner{max-width:1240px;margin:0 auto}.atlas-heading{margin-bottom:3rem}.atlas-eyebrow{display:flex;align-items:center;gap:.7rem;font-size:1rem;line-height:1.65;letter-spacing:.09em;font-weight:650;color:var(--atlas-muted)}.atlas-eyebrow>span{width:.55rem;height:.55rem;background:#2a7156;flex:none}.atlas-heading-row{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,.85fr);gap:3.5rem;align-items:end;margin-top:1.25rem}.atlas-heading h2{font-size:clamp(2.4rem,4.7vw,4.25rem);line-height:1.1;letter-spacing:-.045em;font-weight:600;margin:0}.atlas-heading h2>span{color:var(--atlas-muted)}.atlas-heading-row>p{font-size:1.125rem;line-height:1.85;color:var(--atlas-muted);margin:0 0 .2rem;max-width:33rem}.atlas-signal{border:1px solid var(--atlas-line);border-radius:1.6rem;overflow:hidden;background:#fff}.atlas-skill-navigation{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));padding:1.25rem 1rem .85rem;gap:.35rem;background:#eff3e9}.atlas-skill-button{display:flex;flex-direction:column;align-items:flex-start;position:relative;gap:1rem;min-height:11.5rem;padding:1rem .8rem;border:1px solid transparent;border-radius:1rem;background:transparent;color:var(--atlas-muted);text-align:left;cursor:pointer;transition:background .18s,color .18s,border-color .18s}.atlas-skill-index{font-size:1rem;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.3;opacity:.8}.atlas-skill-icon{display:flex;align-items:center;justify-content:center;width:2.7rem;height:2.7rem;border:1px solid #cdd9ce;border-radius:50%;color:var(--atlas-ink);background:#f9fbf5}.atlas-skill-name{font-size:1rem;line-height:1.45;font-weight:600;overflow-wrap:anywhere}.atlas-skill-arrow{position:absolute;top:1rem;right:.7rem;opacity:0;transition:opacity .18s}.atlas-skill-button.is-selected{color:#123a2c;background:var(--atlas-mint);border-color:#acd975}.atlas-skill-button.is-selected .atlas-skill-icon{background:#173e31;color:#f7ffed;border-color:#173e31}.atlas-skill-button.is-selected .atlas-skill-arrow,.atlas-skill-button:hover .atlas-skill-arrow{opacity:1}.atlas-skill-button:hover:not(.is-selected){background:#e2e9dd;color:var(--atlas-ink)}.atlas-skill-button:focus-visible,.atlas-level-button:focus-visible{outline:3px solid #729d53;outline-offset:3px}.atlas-skill-detail{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:3.5rem;padding:clamp(1.5rem,4vw,3.5rem);background:var(--atlas-deep);color:#f5f9ed}.atlas-detail-label{display:flex;align-items:center;gap:.65rem;font-size:1rem;line-height:1.6;font-weight:600}.atlas-skill-main>.atlas-detail-label{color:#cdf4ae}.atlas-skill-main h3{font-size:clamp(1.65rem,2.5vw,2.4rem);line-height:1.25;letter-spacing:-.025em;font-weight:550;margin:1.2rem 0}.atlas-skill-main>p{font-size:1.125rem;line-height:1.8;margin:0;color:#e0e8dc}.atlas-skill-main>.atlas-skill-context{margin-top:1rem;color:#b9cbbf}.atlas-language-note{display:flex;flex-direction:column;justify-content:center;padding:1.8rem 2rem;border-radius:1rem;background:#dfe9ce;color:#19392b;box-shadow:0 15px 35px #061c1614;transform:rotate(-1.2deg);align-self:center}.atlas-note-label{display:flex;align-items:center;justify-content:space-between;gap:1rem;font-size:1rem;line-height:1.6;font-weight:650;letter-spacing:.04em}.atlas-language-note blockquote{font-size:clamp(1.35rem,2.05vw,1.9rem);font-weight:500;line-height:1.5;letter-spacing:-.02em;margin:1.4rem 0 1.2rem}.atlas-language-note>p{font-size:1.125rem;line-height:1.75;margin:0;color:#46613f}.atlas-example-note{font-size:1rem;line-height:1.7;margin-top:1.5rem;padding-top:1rem;border-top:1px solid #b9c9a8;color:#52694a}.atlas-footnote{display:flex;align-items:flex-start;gap:.7rem;font-size:1rem;line-height:1.8;color:var(--atlas-muted);margin:1.4rem 0 0}.atlas-footnote>svg{flex:none;margin-top:.3rem}.atlas-proficiency{background:#eeefe8;border-top:1px solid var(--atlas-line)}.atlas-pathway-caption{display:flex;justify-content:space-between;align-items:center;gap:1rem;font-size:1rem;font-weight:600;letter-spacing:.04em;line-height:1.6;color:var(--atlas-muted);margin-bottom:1.1rem}.atlas-pathway-caption>svg{flex:1;max-width:13rem;height:1.4rem;min-width:1.5rem}.atlas-level-navigation{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));align-items:end;gap:.6rem;margin-top:2rem}.atlas-level-button{display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-start;gap:.8rem;border:1px solid #bccdbf;border-radius:.8rem .8rem 0 0;padding:calc(1.15rem + var(--atlas-step)*.5rem) 1.2rem 1.2rem;background:#f8faf3;color:var(--atlas-ink);text-align:left;cursor:pointer;transition:background .18s,color .18s,border-color .18s;min-height:13rem}.atlas-level-code{display:block;font-size:2.5rem;font-weight:550;letter-spacing:-.05em;line-height:1.2}.atlas-level-name{display:block;font-size:1rem;font-weight:550;line-height:1.55;min-height:3.1em;overflow-wrap:anywhere}.atlas-level-select{display:flex;justify-content:space-between;gap:.4rem;width:100%;font-size:1rem;line-height:1.5;margin-top:auto;padding-top:.4rem}.atlas-level-select>svg{flex:none}.atlas-level-button.is-selected{background:var(--atlas-mint);border-color:#9bc56e;color:#193727}.atlas-level-button:hover:not(.is-selected){background:#e1e9dc;border-color:#8ca68e}.atlas-level-detail{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:2.5rem;padding:2.8rem;border:1px solid #bccdbf;border-radius:0 0 1rem 1rem;background:#fafbf5}.atlas-level-summary{display:flex;align-items:flex-start;gap:1.7rem}.atlas-selected-code{display:block;color:#5b8054;font-size:clamp(3rem,6vw,5rem);font-weight:500;letter-spacing:-.06em;line-height:1;flex:none}.atlas-level-summary .atlas-detail-label{font-size:1rem;color:var(--atlas-muted);font-weight:600;letter-spacing:.03em}.atlas-level-summary h3{font-size:1.6rem;font-weight:600;line-height:1.4;margin:.45rem 0 .7rem;letter-spacing:-.02em}.atlas-level-summary p,.atlas-level-context>p{font-size:1.125rem;line-height:1.8;color:var(--atlas-muted);margin:0}.atlas-level-context{border-left:1px solid var(--atlas-line);padding-left:2.5rem}.atlas-level-context>.atlas-note-label{margin-top:1.5rem;color:var(--atlas-muted)}.atlas-level-context blockquote{font-size:1.125rem;line-height:1.85;font-weight:550;margin:.6rem 0 0;color:var(--atlas-ink)}.atlas-classification-note{max-width:65rem}.atlas-empty{border:1px solid var(--atlas-line);border-radius:1rem;padding:2rem;font-size:1.125rem;line-height:1.8;color:var(--atlas-muted)}
/* Keep each surface's text independent of the shared public-page reading rules. */
.atlas-section p{font-size:1.125rem}
.atlas-section .atlas-skill-index{opacity:1}
.atlas-section .atlas-heading h2,.atlas-section .atlas-level-summary h3{color:var(--atlas-ink)}
.atlas-section .atlas-heading-row>p,.atlas-section .atlas-level-summary p,.atlas-section .atlas-level-context>p,.atlas-section p.atlas-footnote,.atlas-section p.atlas-empty{color:var(--atlas-muted)}
.atlas-section .atlas-skill-main h3{color:#f5f9ed}
.atlas-section .atlas-skill-main>p{color:#e0e8dc}
.atlas-section .atlas-skill-main>p.atlas-skill-context{color:#b9cbbf}
.atlas-section .atlas-language-note blockquote{color:#19392b}
.atlas-section .atlas-language-note>p{color:#46613f}
.atlas-section .atlas-level-context blockquote{color:var(--atlas-ink)}
.atlas-dark{--atlas-ink:#ecf3e8;--atlas-muted:#b4c7bb;--atlas-line:#335046;--atlas-paper:#0d2421;--atlas-deep:#173e34;background:#0d2421}.atlas-dark.atlas-proficiency{background:#102c26}.atlas-dark .atlas-eyebrow>span{background:#c2fa85}.atlas-dark .atlas-signal{background:#17382e}.atlas-dark .atlas-skill-navigation{background:#17382e}.atlas-dark .atlas-skill-button{color:#c3d3c9}.atlas-dark .atlas-skill-icon{background:#254b3d;border-color:#4b6d59;color:#e0efda}.atlas-dark .atlas-skill-button.is-selected{background:#c2fa85;color:#19372b;border-color:#b7e189}.atlas-dark .atlas-skill-button:hover:not(.is-selected){background:#254b3d;color:#f6faef}.atlas-dark .atlas-skill-button.is-selected .atlas-skill-icon{background:#173e31;color:#f7ffed;border-color:#173e31}.atlas-dark .atlas-level-button{background:#193b30;border-color:#4a6855;color:#eef4e9}.atlas-dark .atlas-level-button.is-selected{background:#c2fa85;color:#193727;border-color:#b7e189}.atlas-dark .atlas-level-button:hover:not(.is-selected){background:#2b4d3b;border-color:#719070}.atlas-dark .atlas-level-detail{background:#17372e;border-color:#4a6855}.atlas-dark .atlas-selected-code{color:#b5df91}
@media(min-width:1100px){.atlas-skill-button:nth-child(even){margin-top:.65rem}.atlas-skill-button:nth-child(odd){margin-bottom:.65rem}}
@media(max-width:1100px){.atlas-skill-navigation{grid-template-columns:repeat(4,minmax(0,1fr));gap:.5rem}.atlas-skill-button{min-height:9.5rem;gap:.7rem}.atlas-skill-detail{gap:2rem}.atlas-level-navigation{gap:.45rem}.atlas-level-button{padding-right:.8rem;padding-left:.8rem}.atlas-level-detail{padding:2rem;gap:1.8rem}.atlas-level-summary{gap:1rem}.atlas-level-context{padding-left:1.8rem}}
@media(max-width:850px){.atlas-heading-row{grid-template-columns:minmax(0,1fr);gap:1.4rem}.atlas-heading-row>p{max-width:39rem}.atlas-heading{margin-bottom:2rem}.atlas-skill-detail{grid-template-columns:minmax(0,1fr);gap:2rem;padding:2rem}.atlas-language-note{transform:none}.atlas-level-navigation{grid-template-columns:repeat(3,minmax(0,1fr));align-items:stretch;gap:.65rem}.atlas-level-button{padding:1.2rem;min-height:12rem;border-radius:.7rem}.atlas-level-name{min-height:3.1em}.atlas-level-detail{grid-template-columns:minmax(0,1fr);border-radius:1rem;margin-top:1rem}.atlas-level-context{padding:1.5rem 0 0;border-left:0;border-top:1px solid var(--atlas-line)}.atlas-pathway-caption{font-size:1rem;letter-spacing:0}.atlas-pathway-caption>span{max-width:12rem}.atlas-pathway-caption>span:last-child{text-align:right}}
@media(max-width:520px){.atlas-section{padding-right:1rem;padding-left:1rem}.atlas-heading h2{font-size:2.4rem}.atlas-heading-row{gap:1.25rem}.atlas-skill-navigation{grid-template-columns:repeat(2,minmax(0,1fr));padding:.65rem;gap:.4rem}.atlas-skill-button{min-height:10rem;padding:.9rem;gap:.8rem}.atlas-skill-button:last-child:nth-child(odd){grid-column:1/-1;display:grid;grid-template-columns:auto auto minmax(0,1fr);align-items:center;min-height:5rem}.atlas-skill-button:last-child:nth-child(odd) .atlas-skill-name{padding-right:1rem}.atlas-skill-button:last-child:nth-child(odd) .atlas-skill-arrow{display:none}.atlas-skill-detail{padding:1.4rem;gap:1.6rem}.atlas-language-note{padding:1.3rem}.atlas-language-note blockquote{font-size:1.35rem}.atlas-note-label{letter-spacing:0}.atlas-level-navigation{grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem;margin-top:1.3rem}.atlas-level-button{min-height:13rem;padding:1.1rem .85rem;gap:.7rem}.atlas-level-code{font-size:2.4rem}.atlas-level-select{flex-wrap:wrap;justify-content:flex-start;align-items:center;gap:.25rem}.atlas-level-detail{padding:1.4rem}.atlas-level-summary{flex-direction:column;gap:1.2rem}.atlas-selected-code{font-size:3.5rem}.atlas-pathway-caption{gap:.7rem;align-items:center}.atlas-pathway-caption>span{max-width:8.5rem}.atlas-pathway-caption>svg{flex:none}.atlas-eyebrow{letter-spacing:.035em}.atlas-level-summary h3{font-size:1.6rem}}
@media(prefers-reduced-motion:reduce){.atlas-skill-button,.atlas-skill-arrow,.atlas-level-button{transition:none}.atlas-language-note{transform:none}}
</style>
