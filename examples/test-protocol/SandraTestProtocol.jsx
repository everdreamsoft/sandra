import React, { useState } from 'react';

const TESTS = [
  {
    id: 1,
    title: 'Stockage basique',
    category: 'core',
    steps: [
      { action: 'store', text: "Je m'appelle [ton pr\u00e9nom]. Je travaille comme [ton job] chez [ton entreprise]." },
      { action: 'verify', text: "Qu'est-ce que tu sais sur moi ?" },
    ],
    expected: 'Claude restitue ton nom, job et entreprise.',
  },
  {
    id: 2,
    title: 'Pr\u00e9f\u00e9rences',
    category: 'core',
    steps: [
      { action: 'store', text: "J'adore le sushi et je d\u00e9teste les \u00e9pinards." },
      { action: 'verify', text: "Qu'est-ce que j'aime manger ?" },
    ],
    expected: 'Sushi mentionn\u00e9. \u00c9pinards mentionn\u00e9s comme non-aim\u00e9s.',
  },
  {
    id: 3,
    title: 'Contacts / personnes',
    category: 'core',
    steps: [
      { action: 'store', text: 'Mon manager s\'appelle Marc Dupont. Il est responsable marketing.' },
      { action: 'verify', text: 'Qui est Marc ?' },
    ],
    expected: 'Marc Dupont, responsable marketing, identifi\u00e9 comme ton manager.',
  },
  {
    id: 4,
    title: 'Accumulation multi-sessions',
    category: 'core',
    steps: [
      { action: 'store', text: "J'ai un chien qui s'appelle Pixel." },
      { action: 'store', text: 'Mon chat s\'appelle Luna.' },
      { action: 'verify', text: 'Quels sont mes animaux ?' },
    ],
    expected: 'Pixel (chien) et Luna (chat).',
  },
  {
    id: 5,
    title: 'Mise \u00e0 jour d\'information',
    category: 'core',
    steps: [
      { action: 'store', text: 'Je travaille chez Google.' },
      { action: 'store', text: "En fait j'ai chang\u00e9 de bo\u00eete, je travaille maintenant chez Apple." },
      { action: 'verify', text: 'O\u00f9 est-ce que je travaille ?' },
    ],
    expected: 'Apple (pas Google). Ancienne info corrig\u00e9e.',
  },
  {
    id: 6,
    title: 'T\u00e2ches et rappels',
    category: 'features',
    steps: [
      { action: 'store', text: 'Rappelle-moi que je dois appeler le dentiste la semaine prochaine.' },
      { action: 'verify', text: "J'ai des rappels en attente ?" },
    ],
    expected: 'Le dentiste appara\u00eet.',
  },
  {
    id: 7,
    title: 'Recherche s\u00e9mantique (reformulation)',
    category: 'features',
    steps: [
      { action: 'store', text: "Mon budget annuel pour le marketing est de 50'000 euros." },
      { action: 'verify', text: 'Combien je d\u00e9pense pour la publicit\u00e9 ?' },
    ],
    expected: 'Retrouve l\'info marketing 50k m\u00eame avec le mot "publicit\u00e9".',
  },
  {
    id: 8,
    title: 'Multilingue',
    category: 'features',
    steps: [
      { action: 'store', text: 'Mon restaurant pr\u00e9f\u00e9r\u00e9 c\'est Chez Paul. (en fran\u00e7ais)' },
      { action: 'verify', text: "What's my favorite restaurant? (en anglais)" },
    ],
    expected: 'Chez Paul.',
  },
  {
    id: 9,
    title: 'Relations entre personnes',
    category: 'features',
    steps: [
      { action: 'store', text: "Sophie est ma s\u0153ur. Elle habite \u00e0 Lyon. Son mari s'appelle Thomas." },
      { action: 'verify', text: 'Qui est Thomas ?' },
    ],
    expected: 'Le mari de Sophie (ta s\u0153ur), habite \u00e0 Lyon.',
  },
  {
    id: 10,
    title: 'Info complexe / document',
    category: 'advanced',
    steps: [
      { action: 'store', text: "Mon num\u00e9ro de contrat est CT-2024-789. C'est un contrat freelance avec l'agence Creativa, sign\u00e9 en mars 2024 pour 12 mois \u00e0 4000\u20ac/mois." },
      { action: 'verify', text: 'Donne-moi les d\u00e9tails de mon contrat.' },
    ],
    expected: 'Num\u00e9ro, agence, date, dur\u00e9e, montant.',
  },
  {
    id: 11,
    title: 'Oubli volontaire',
    category: 'advanced',
    steps: [
      { action: 'store', text: 'Oublie tout ce que tu sais sur mon contrat.' },
      { action: 'verify', text: "C'est quoi mon contrat ?" },
    ],
    expected: 'Claude ne retrouve plus l\'info.',
  },
  {
    id: 12,
    title: 'Isolation entre utilisateurs',
    category: 'security',
    steps: [
      { action: 'verify', text: 'Se connecter avec un autre token/env et v\u00e9rifier qu\'on ne voit PAS les donn\u00e9es du premier utilisateur.' },
    ],
    expected: 'Z\u00e9ro cross-contamination.',
  },
  {
    id: 13,
    title: 'Stress m\u00e9moire (10+ faits)',
    category: 'advanced',
    steps: [
      {
        action: 'store',
        text: `Envoyer d'un coup :\n- Mon chiffre porte-bonheur est 7\n- Je suis n\u00e9e le 3 mars\n- Ma couleur pr\u00e9f\u00e9r\u00e9e est le violet\n- J'ai deux fr\u00e8res : Karim et Youssef\n- Mon film pr\u00e9f\u00e9r\u00e9 c'est Inception\n- Je parle fran\u00e7ais, anglais et un peu d'arabe\n- Mon objectif 2026 c'est de lancer ma bo\u00eete\n- J'ai fait mes \u00e9tudes \u00e0 l'EPFL\n- Mon hobby c'est la poterie\n- Ma chanson pr\u00e9f\u00e9r\u00e9e c'est "Bohemian Rhapsody"`,
      },
      { action: 'verify', text: 'Fais un r\u00e9sum\u00e9 complet de ce que tu sais sur moi.' },
    ],
    expected: 'Au moins 8/10 faits retrouv\u00e9s.',
  },
  {
    id: 14,
    title: 'Mobile',
    category: 'platform',
    steps: [
      { action: 'verify', text: 'Reprendre les tests 1, 2, 5 depuis l\'app Claude sur t\u00e9l\u00e9phone.' },
    ],
    expected: 'M\u00eames r\u00e9sultats que sur le web.',
  },
];

const STATUS_COLORS = {
  pending: '#94a3b8',
  ok: '#22c55e',
  partial: '#f59e0b',
  ko: '#ef4444',
};

const STATUS_LABELS = {
  pending: 'En attente',
  ok: 'OK',
  partial: 'Partiel',
  ko: 'KO',
};

const CATEGORY_LABELS = {
  core: 'Fonctions de base',
  features: 'Fonctionnalit\u00e9s avanc\u00e9es',
  advanced: 'Tests avanc\u00e9s',
  security: 'S\u00e9curit\u00e9',
  platform: 'Multi-plateforme',
};

export default function SandraTestProtocol() {
  const [results, setResults] = useState(
    TESTS.reduce((acc, t) => ({ ...acc, [t.id]: { status: 'pending', comment: '', responseTime: '' } }), {})
  );
  const [testerName, setTesterName] = useState('');
  const [expandedTest, setExpandedTest] = useState(null);

  const updateResult = (id, field, value) => {
    setResults((prev) => ({
      ...prev,
      [id]: { ...prev[id], [field]: value },
    }));
  };

  const stats = Object.values(results).reduce(
    (acc, r) => {
      acc[r.status] = (acc[r.status] || 0) + 1;
      return acc;
    },
    { pending: 0, ok: 0, partial: 0, ko: 0 }
  );

  const total = TESTS.length;
  const completed = total - stats.pending;
  const score = stats.ok + stats.partial * 0.5;

  const exportResults = () => {
    const data = {
      tester: testerName,
      date: new Date().toISOString().slice(0, 10),
      score: `${score}/${total}`,
      stats,
      tests: TESTS.map((t) => ({
        id: t.id,
        title: t.title,
        ...results[t.id],
      })),
    };
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `sandra-test-${testerName || 'results'}-${data.date}.json`;
    a.click();
    URL.revokeObjectURL(url);
  };

  const categories = [...new Set(TESTS.map((t) => t.category))];

  return (
    <div style={{ fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif', maxWidth: 800, margin: '0 auto', padding: '24px 16px', background: '#fafafa', minHeight: '100vh' }}>
      {/* Header */}
      <div style={{ background: '#1e293b', color: 'white', borderRadius: 16, padding: '32px 28px', marginBottom: 24 }}>
        <h1 style={{ margin: 0, fontSize: 28, fontWeight: 700 }}>Sandra Memory Agent</h1>
        <p style={{ margin: '8px 0 0', opacity: 0.7, fontSize: 14 }}>Protocole de test</p>

        <div style={{ display: 'flex', gap: 16, marginTop: 24, flexWrap: 'wrap' }}>
          <div style={{ background: 'rgba(255,255,255,0.1)', borderRadius: 10, padding: '12px 20px', flex: 1, minWidth: 120, textAlign: 'center' }}>
            <div style={{ fontSize: 28, fontWeight: 700 }}>{completed}/{total}</div>
            <div style={{ fontSize: 12, opacity: 0.6 }}>termin\u00e9s</div>
          </div>
          <div style={{ background: 'rgba(34,197,94,0.2)', borderRadius: 10, padding: '12px 20px', flex: 1, minWidth: 120, textAlign: 'center' }}>
            <div style={{ fontSize: 28, fontWeight: 700, color: '#22c55e' }}>{stats.ok}</div>
            <div style={{ fontSize: 12, opacity: 0.6 }}>OK</div>
          </div>
          <div style={{ background: 'rgba(245,158,11,0.2)', borderRadius: 10, padding: '12px 20px', flex: 1, minWidth: 120, textAlign: 'center' }}>
            <div style={{ fontSize: 28, fontWeight: 700, color: '#f59e0b' }}>{stats.partial}</div>
            <div style={{ fontSize: 12, opacity: 0.6 }}>Partiel</div>
          </div>
          <div style={{ background: 'rgba(239,68,68,0.2)', borderRadius: 10, padding: '12px 20px', flex: 1, minWidth: 120, textAlign: 'center' }}>
            <div style={{ fontSize: 28, fontWeight: 700, color: '#ef4444' }}>{stats.ko}</div>
            <div style={{ fontSize: 12, opacity: 0.6 }}>KO</div>
          </div>
        </div>

        {/* Progress bar */}
        <div style={{ marginTop: 20, background: 'rgba(255,255,255,0.1)', borderRadius: 8, height: 8, overflow: 'hidden' }}>
          <div style={{ height: '100%', width: `${(completed / total) * 100}%`, background: 'linear-gradient(90deg, #22c55e, #3b82f6)', borderRadius: 8, transition: 'width 0.3s ease' }} />
        </div>
      </div>

      {/* Tester name */}
      <div style={{ marginBottom: 24 }}>
        <input
          type="text"
          placeholder="Ton pr\u00e9nom (pour l'export)"
          value={testerName}
          onChange={(e) => setTesterName(e.target.value)}
          style={{ width: '100%', padding: '12px 16px', borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 15, boxSizing: 'border-box', background: 'white' }}
        />
      </div>

      {/* Instructions */}
      <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 12, padding: '16px 20px', marginBottom: 24, fontSize: 13, color: '#1e40af', lineHeight: 1.6 }}>
        <strong>Instructions :</strong>
        <ul style={{ margin: '8px 0 0', paddingLeft: 20 }}>
          <li>Chaque \u00e9tape <span style={{ background: '#dbeafe', padding: '2px 8px', borderRadius: 4, fontWeight: 600 }}>STORE</span> = une <strong>nouvelle conversation</strong> Claude</li>
          <li>Chaque \u00e9tape <span style={{ background: '#dcfce7', padding: '2px 8px', borderRadius: 4, fontWeight: 600 }}>VERIFY</span> = encore une <strong>nouvelle conversation</strong></li>
          <li>Si Claude ne se souvient pas, essayer de reformuler avant de noter KO</li>
          <li>V\u00e9rifier que le connector Sandra est "Connected" dans Settings &rarr; Connectors</li>
        </ul>
      </div>

      {/* Tests by category */}
      {categories.map((cat) => (
        <div key={cat} style={{ marginBottom: 32 }}>
          <h2 style={{ fontSize: 16, fontWeight: 600, color: '#64748b', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 12 }}>
            {CATEGORY_LABELS[cat] || cat}
          </h2>

          {TESTS.filter((t) => t.category === cat).map((test) => {
            const result = results[test.id];
            const isExpanded = expandedTest === test.id;

            return (
              <div
                key={test.id}
                style={{
                  background: 'white',
                  borderRadius: 12,
                  marginBottom: 8,
                  border: `2px solid ${isExpanded ? '#3b82f6' : '#e2e8f0'}`,
                  overflow: 'hidden',
                  transition: 'border-color 0.2s ease',
                }}
              >
                {/* Test header */}
                <div
                  onClick={() => setExpandedTest(isExpanded ? null : test.id)}
                  style={{ display: 'flex', alignItems: 'center', padding: '14px 16px', cursor: 'pointer', gap: 12 }}
                >
                  <div
                    style={{
                      width: 12,
                      height: 12,
                      borderRadius: '50%',
                      background: STATUS_COLORS[result.status],
                      flexShrink: 0,
                    }}
                  />
                  <span style={{ fontSize: 13, color: '#94a3b8', fontWeight: 600, minWidth: 24 }}>#{test.id}</span>
                  <span style={{ fontSize: 15, fontWeight: 500, flex: 1 }}>{test.title}</span>
                  <span style={{ fontSize: 12, color: STATUS_COLORS[result.status], fontWeight: 600 }}>
                    {STATUS_LABELS[result.status]}
                  </span>
                  <span style={{ fontSize: 18, color: '#94a3b8', transform: isExpanded ? 'rotate(180deg)' : 'none', transition: 'transform 0.2s' }}>
                    &#9662;
                  </span>
                </div>

                {/* Expanded content */}
                {isExpanded && (
                  <div style={{ padding: '0 16px 16px', borderTop: '1px solid #f1f5f9' }}>
                    {/* Steps */}
                    <div style={{ marginTop: 12 }}>
                      {test.steps.map((step, i) => (
                        <div key={i} style={{ display: 'flex', gap: 10, marginBottom: 10, alignItems: 'flex-start' }}>
                          <span
                            style={{
                              fontSize: 11,
                              fontWeight: 700,
                              padding: '3px 8px',
                              borderRadius: 6,
                              background: step.action === 'store' ? '#dbeafe' : '#dcfce7',
                              color: step.action === 'store' ? '#1d4ed8' : '#15803d',
                              flexShrink: 0,
                              marginTop: 2,
                            }}
                          >
                            {step.action === 'store' ? 'STORE' : 'VERIFY'}
                          </span>
                          <span style={{ fontSize: 14, color: '#334155', whiteSpace: 'pre-wrap', lineHeight: 1.5 }}>{step.text}</span>
                        </div>
                      ))}
                    </div>

                    {/* Expected */}
                    <div style={{ background: '#f8fafc', borderRadius: 8, padding: '10px 14px', marginTop: 8, fontSize: 13, color: '#475569' }}>
                      <strong>Attendu :</strong> {test.expected}
                    </div>

                    {/* Status buttons */}
                    <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
                      {['ok', 'partial', 'ko'].map((s) => (
                        <button
                          key={s}
                          onClick={() => updateResult(test.id, 'status', s)}
                          style={{
                            padding: '8px 20px',
                            borderRadius: 8,
                            border: result.status === s ? `2px solid ${STATUS_COLORS[s]}` : '2px solid #e2e8f0',
                            background: result.status === s ? `${STATUS_COLORS[s]}15` : 'white',
                            color: result.status === s ? STATUS_COLORS[s] : '#64748b',
                            fontWeight: 600,
                            fontSize: 13,
                            cursor: 'pointer',
                            transition: 'all 0.15s ease',
                          }}
                        >
                          {STATUS_LABELS[s]}
                        </button>
                      ))}
                    </div>

                    {/* Comment */}
                    <textarea
                      placeholder="Commentaire (optionnel) : ce qui a march\u00e9, ce qui a \u00e9chou\u00e9, erreurs..."
                      value={result.comment}
                      onChange={(e) => updateResult(test.id, 'comment', e.target.value)}
                      style={{
                        width: '100%',
                        marginTop: 10,
                        padding: '10px 14px',
                        borderRadius: 8,
                        border: '1px solid #e2e8f0',
                        fontSize: 13,
                        minHeight: 60,
                        resize: 'vertical',
                        boxSizing: 'border-box',
                        fontFamily: 'inherit',
                      }}
                    />
                  </div>
                )}
              </div>
            );
          })}
        </div>
      ))}

      {/* Export */}
      <div style={{ textAlign: 'center', padding: '20px 0 40px' }}>
        <button
          onClick={exportResults}
          style={{
            padding: '14px 32px',
            borderRadius: 12,
            border: 'none',
            background: '#1e293b',
            color: 'white',
            fontSize: 15,
            fontWeight: 600,
            cursor: 'pointer',
          }}
        >
          Exporter les r\u00e9sultats (JSON)
        </button>
      </div>
    </div>
  );
}
