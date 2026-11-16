{{-- Mapa de estados vetorizado do arquivo fornecido pelo usuário. 27 regiões independentes. --}}
<div class="ds-v5-map ds-v7-map-interactive"
     x-data="{active: null, tipX: 6, tipY: 6, states: @js($dsMapTooltips), track(event) { const r = this.$el.getBoundingClientRect(); this.tipX = Math.max(4, Math.min(r.width - 149, event.clientX - r.left + 10)); this.tipY = Math.max(4, Math.min(r.height - 78, event.clientY - r.top - 35)); } }"
     x-on:mouseleave="active = null">
    <svg class="ds-v7-map-svg" viewBox="0 0 554 554" role="group"
         aria-label="Mapa do Brasil: passe o mouse sobre um estado para consultar quantidade e percentual de estabelecimentos">
        <path class="ds-v7-state" data-uf="RR" d="M202,35 L194,42 L193,45 L191,44 L188,45 L186,48 L183,48 L182,50 L175,49 L174,51 L171,51 L172,54 L169,57 L163,52 L162,53 L159,52 L153,53 L152,50 L150,49 L148,50 L149,52 L151,52 L154,55 L153,61 L156,64 L156,68 L162,67 L165,70 L165,72 L170,73 L173,76 L171,79 L172,80 L171,82 L176,88 L174,91 L175,92 L174,99 L177,107 L175,109 L182,115 L185,114 L185,108 L189,104 L192,104 L197,108 L199,108 L200,107 L199,104 L205,95 L218,95 L217,83 L214,83 L206,75 L207,71 L205,70 L203,65 L203,61 L206,58 L205,54 L208,52 L209,49 L207,47 L207,44 L203,44 L201,42 L203,39 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['RR']['name'] }}: {{ $dsMapTooltips['RR']['total'] }} {{ $dsMapTooltips['RR']['unit'] }}; {{ $dsMapTooltips['RR']['percentage'] }} da base"
              x-on:mouseenter="active = 'RR'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'RR'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'RR' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'RR')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'RR')"
              wire:click="openExplorer('state', 'RR')">
            <title>{{ $dsMapTooltips['RR']['name'] }}: {{ $dsMapTooltips['RR']['total'] }} {{ $dsMapTooltips['RR']['unit'] }} ({{ $dsMapTooltips['RR']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="AP" d="M312,46 L314,48 L312,50 L311,49 L307,53 L300,66 L301,67 L296,72 L290,73 L286,72 L284,70 L279,74 L277,74 L276,72 L273,71 L272,72 L272,75 L277,75 L283,80 L286,79 L289,82 L289,87 L293,89 L292,93 L295,96 L295,99 L299,102 L301,108 L305,112 L308,111 L307,109 L312,103 L315,103 L316,99 L319,99 L320,101 L329,93 L328,92 L329,90 L327,88 L331,82 L330,78 L327,79 L323,75 L320,74 L321,70 L316,59 L317,52 L314,50 L315,48 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['AP']['name'] }}: {{ $dsMapTooltips['AP']['total'] }} {{ $dsMapTooltips['AP']['unit'] }}; {{ $dsMapTooltips['AP']['percentage'] }} da base"
              x-on:mouseenter="active = 'AP'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'AP'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'AP' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'AP')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'AP')"
              wire:click="openExplorer('state', 'AP')">
            <title>{{ $dsMapTooltips['AP']['name'] }}: {{ $dsMapTooltips['AP']['total'] }} {{ $dsMapTooltips['AP']['unit'] }} ({{ $dsMapTooltips['AP']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="PA" d="M222,84 L220,86 L220,102 L223,107 L230,112 L235,118 L239,118 L246,122 L247,124 L253,125 L253,129 L244,146 L235,168 L232,172 L229,180 L226,183 L230,187 L230,189 L235,196 L236,199 L235,206 L245,214 L249,213 L251,215 L272,216 L273,217 L290,217 L291,218 L326,219 L328,213 L335,207 L339,199 L339,197 L337,195 L337,192 L339,190 L338,185 L342,181 L345,181 L351,173 L350,170 L352,169 L347,168 L345,165 L357,155 L360,155 L362,153 L363,149 L366,148 L367,143 L373,136 L371,133 L374,131 L374,128 L377,124 L376,120 L378,116 L374,115 L371,111 L370,112 L366,111 L365,108 L364,111 L359,110 L357,111 L356,110 L354,112 L353,117 L351,119 L348,119 L345,124 L342,124 L338,133 L335,132 L335,128 L338,123 L333,127 L331,126 L330,123 L328,125 L323,124 L321,126 L317,126 L314,124 L315,128 L313,130 L310,127 L310,122 L312,119 L314,118 L320,121 L322,118 L325,120 L330,118 L332,119 L342,118 L344,116 L344,114 L348,112 L348,106 L350,104 L344,104 L341,102 L338,105 L325,102 L323,105 L324,107 L321,115 L319,113 L312,118 L310,118 L309,115 L315,107 L312,107 L310,109 L311,112 L309,114 L303,114 L298,109 L297,104 L292,100 L292,96 L289,93 L290,90 L286,86 L287,83 L282,82 L277,78 L272,78 L269,75 L270,69 L268,68 L263,70 L257,70 L256,68 L255,70 L258,73 L258,75 L256,77 L248,75 L245,77 L242,76 L241,74 L236,79 L233,79 L229,82 L227,81 L224,84 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['PA']['name'] }}: {{ $dsMapTooltips['PA']['total'] }} {{ $dsMapTooltips['PA']['unit'] }}; {{ $dsMapTooltips['PA']['percentage'] }} da base"
              x-on:mouseenter="active = 'PA'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'PA'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'PA' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'PA')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'PA')"
              wire:click="openExplorer('state', 'PA')">
            <title>{{ $dsMapTooltips['PA']['name'] }}: {{ $dsMapTooltips['PA']['total'] }} {{ $dsMapTooltips['PA']['unit'] }} ({{ $dsMapTooltips['PA']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="AM" d="M250,126 L247,127 L241,122 L235,121 L219,106 L218,98 L205,98 L202,104 L203,108 L201,110 L197,111 L192,107 L189,109 L188,108 L187,109 L188,111 L187,118 L181,119 L179,117 L179,115 L175,113 L172,109 L174,107 L174,104 L171,99 L172,93 L171,91 L173,88 L169,83 L170,77 L166,76 L163,73 L159,75 L158,77 L155,76 L155,79 L151,84 L148,83 L145,87 L143,86 L136,94 L133,92 L133,89 L129,93 L125,93 L118,87 L115,88 L114,87 L115,82 L112,77 L110,77 L107,81 L105,81 L102,78 L103,80 L101,82 L85,82 L84,83 L83,82 L82,88 L89,86 L92,91 L92,94 L90,96 L83,95 L80,97 L81,100 L80,104 L86,109 L87,114 L89,116 L89,120 L87,123 L87,133 L84,149 L84,156 L79,159 L76,156 L71,157 L71,159 L69,161 L66,160 L64,162 L59,161 L53,165 L49,170 L47,170 L47,176 L43,181 L45,183 L45,187 L39,191 L37,194 L59,201 L67,201 L83,205 L122,223 L129,217 L137,218 L141,215 L144,217 L146,211 L154,210 L156,207 L155,204 L158,201 L160,201 L161,199 L166,197 L173,198 L182,207 L185,205 L186,206 L223,206 L224,205 L223,203 L226,199 L224,196 L227,193 L227,188 L224,184 L224,181 L228,177 L228,174 L232,169 L232,166 L250,129 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['AM']['name'] }}: {{ $dsMapTooltips['AM']['total'] }} {{ $dsMapTooltips['AM']['unit'] }}; {{ $dsMapTooltips['AM']['percentage'] }} da base"
              x-on:mouseenter="active = 'AM'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'AM'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'AM' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'AM')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'AM')"
              wire:click="openExplorer('state', 'AM')">
            <title>{{ $dsMapTooltips['AM']['name'] }}: {{ $dsMapTooltips['AM']['total'] }} {{ $dsMapTooltips['AM']['unit'] }} ({{ $dsMapTooltips['AM']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="MA" d="M381,116 L379,121 L380,124 L375,133 L375,137 L369,145 L369,148 L365,151 L364,155 L361,158 L357,158 L352,163 L353,162 L355,164 L357,164 L364,171 L365,177 L364,185 L360,189 L363,191 L363,193 L367,199 L370,197 L373,197 L376,201 L375,204 L371,207 L371,209 L368,212 L374,219 L374,222 L376,225 L378,226 L380,219 L379,209 L382,205 L385,195 L387,193 L395,190 L396,191 L399,188 L401,188 L404,184 L410,183 L412,185 L417,184 L417,179 L415,177 L415,172 L419,166 L419,164 L417,162 L418,158 L417,153 L421,149 L421,146 L424,143 L431,140 L431,139 L424,138 L419,133 L414,132 L413,134 L408,134 L396,144 L394,141 L396,139 L397,132 L400,129 L398,131 L396,131 L395,129 L398,125 L395,122 L393,122 L392,120 L392,123 L389,124 L387,122 L388,119 L384,119 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['MA']['name'] }}: {{ $dsMapTooltips['MA']['total'] }} {{ $dsMapTooltips['MA']['unit'] }}; {{ $dsMapTooltips['MA']['percentage'] }} da base"
              x-on:mouseenter="active = 'MA'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'MA'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'MA' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'MA')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'MA')"
              wire:click="openExplorer('state', 'MA')">
            <title>{{ $dsMapTooltips['MA']['name'] }}: {{ $dsMapTooltips['MA']['total'] }} {{ $dsMapTooltips['MA']['unit'] }} ({{ $dsMapTooltips['MA']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="CE" d="M441,140 L438,145 L443,155 L443,157 L441,159 L445,172 L444,177 L446,179 L446,185 L450,188 L450,190 L447,193 L458,194 L467,201 L471,196 L468,192 L468,190 L470,188 L471,181 L476,178 L477,174 L481,170 L482,167 L486,164 L484,164 L482,161 L480,161 L477,158 L477,156 L464,145 L452,139 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['CE']['name'] }}: {{ $dsMapTooltips['CE']['total'] }} {{ $dsMapTooltips['CE']['unit'] }}; {{ $dsMapTooltips['CE']['percentage'] }} da base"
              x-on:mouseenter="active = 'CE'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'CE'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'CE' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'CE')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'CE')"
              wire:click="openExplorer('state', 'CE')">
            <title>{{ $dsMapTooltips['CE']['name'] }}: {{ $dsMapTooltips['CE']['total'] }} {{ $dsMapTooltips['CE']['unit'] }} ({{ $dsMapTooltips['CE']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="RN" d="M474,182 L475,184 L478,185 L483,180 L488,179 L490,181 L490,183 L486,188 L488,188 L489,190 L492,187 L495,190 L497,187 L496,186 L498,184 L505,186 L508,185 L514,187 L512,185 L513,182 L511,180 L512,176 L508,171 L495,172 L492,169 L489,169 L488,167 L486,167 L483,170 L483,172 L479,176 L477,181 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['RN']['name'] }}: {{ $dsMapTooltips['RN']['total'] }} {{ $dsMapTooltips['RN']['unit'] }}; {{ $dsMapTooltips['RN']['percentage'] }} da base"
              x-on:mouseenter="active = 'RN'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'RN'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'RN' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'RN')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'RN')"
              wire:click="openExplorer('state', 'RN')">
            <title>{{ $dsMapTooltips['RN']['name'] }}: {{ $dsMapTooltips['RN']['total'] }} {{ $dsMapTooltips['RN']['unit'] }} ({{ $dsMapTooltips['RN']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="PI" d="M438,141 L434,140 L429,145 L424,146 L423,150 L420,153 L421,157 L420,162 L422,164 L422,166 L417,174 L420,179 L420,185 L418,187 L413,188 L406,186 L396,194 L395,193 L393,195 L387,196 L385,205 L381,211 L383,218 L381,227 L386,228 L387,232 L390,234 L393,234 L398,231 L401,232 L406,225 L404,220 L406,217 L416,216 L418,219 L421,219 L424,216 L430,216 L431,213 L433,213 L434,211 L445,203 L443,196 L445,194 L447,188 L443,186 L443,179 L441,177 L442,174 L440,168 L441,166 L439,164 L438,160 L440,157 L440,154 L438,152 L436,146 L436,143 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['PI']['name'] }}: {{ $dsMapTooltips['PI']['total'] }} {{ $dsMapTooltips['PI']['unit'] }}; {{ $dsMapTooltips['PI']['percentage'] }} da base"
              x-on:mouseenter="active = 'PI'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'PI'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'PI' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'PI')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'PI')"
              wire:click="openExplorer('state', 'PI')">
            <title>{{ $dsMapTooltips['PI']['name'] }}: {{ $dsMapTooltips['PI']['total'] }} {{ $dsMapTooltips['PI']['unit'] }} ({{ $dsMapTooltips['PI']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="PB" d="M471,190 L471,192 L474,195 L472,199 L479,200 L484,196 L489,195 L492,198 L490,202 L491,205 L490,207 L492,207 L498,202 L503,203 L513,197 L516,200 L517,199 L514,190 L509,188 L499,187 L499,189 L496,193 L494,193 L492,190 L490,192 L484,190 L483,188 L486,184 L485,182 L481,187 L476,188 L473,186 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['PB']['name'] }}: {{ $dsMapTooltips['PB']['total'] }} {{ $dsMapTooltips['PB']['unit'] }}; {{ $dsMapTooltips['PB']['percentage'] }} da base"
              x-on:mouseenter="active = 'PB'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'PB'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'PB' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'PB')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'PB')"
              wire:click="openExplorer('state', 'PB')">
            <title>{{ $dsMapTooltips['PB']['name'] }}: {{ $dsMapTooltips['PB']['total'] }} {{ $dsMapTooltips['PB']['unit'] }} ({{ $dsMapTooltips['PB']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="PE" d="M441,208 L444,212 L443,215 L446,217 L446,220 L450,215 L452,215 L457,210 L461,208 L465,212 L468,212 L470,214 L471,213 L473,216 L476,217 L476,219 L481,214 L489,221 L496,221 L505,214 L511,218 L513,215 L513,209 L515,202 L513,200 L510,201 L508,204 L503,206 L498,205 L492,210 L490,210 L488,208 L488,206 L486,205 L487,201 L489,199 L487,197 L479,203 L473,202 L472,203 L470,201 L467,204 L460,199 L458,199 L457,197 L447,196 L448,202 L445,207 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['PE']['name'] }}: {{ $dsMapTooltips['PE']['total'] }} {{ $dsMapTooltips['PE']['unit'] }}; {{ $dsMapTooltips['PE']['percentage'] }} da base"
              x-on:mouseenter="active = 'PE'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'PE'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'PE' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'PE')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'PE')"
              wire:click="openExplorer('state', 'PE')">
            <title>{{ $dsMapTooltips['PE']['name'] }}: {{ $dsMapTooltips['PE']['total'] }} {{ $dsMapTooltips['PE']['unit'] }} ({{ $dsMapTooltips['PE']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="AC" d="M35,200 L39,204 L38,205 L41,207 L41,210 L43,210 L44,213 L48,216 L49,220 L47,222 L50,221 L52,223 L53,222 L56,223 L59,227 L59,229 L62,228 L67,229 L76,220 L78,220 L79,226 L77,229 L79,239 L81,241 L85,238 L88,238 L90,240 L92,238 L97,240 L100,238 L102,241 L109,233 L112,234 L116,229 L119,229 L122,226 L81,207 L68,204 L56,203 L39,197 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['AC']['name'] }}: {{ $dsMapTooltips['AC']['total'] }} {{ $dsMapTooltips['AC']['unit'] }}; {{ $dsMapTooltips['AC']['percentage'] }} da base"
              x-on:mouseenter="active = 'AC'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'AC'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'AC' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'AC')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'AC')"
              wire:click="openExplorer('state', 'AC')">
            <title>{{ $dsMapTooltips['AC']['name'] }}: {{ $dsMapTooltips['AC']['total'] }} {{ $dsMapTooltips['AC']['unit'] }} ({{ $dsMapTooltips['AC']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="TO" d="M349,165 L354,167 L355,169 L353,171 L354,173 L346,183 L341,185 L342,190 L340,192 L340,194 L342,196 L341,202 L338,207 L331,213 L330,219 L326,226 L326,229 L323,232 L324,237 L322,240 L323,244 L322,257 L324,253 L327,252 L330,255 L328,259 L334,262 L336,262 L340,256 L342,256 L346,261 L345,263 L350,260 L352,260 L355,263 L357,261 L359,261 L361,263 L363,261 L368,261 L371,258 L375,258 L372,254 L372,252 L375,249 L374,248 L375,246 L370,243 L370,240 L374,236 L374,233 L379,230 L373,226 L371,223 L371,220 L365,212 L368,208 L368,206 L373,202 L373,200 L370,200 L369,202 L367,202 L363,198 L360,191 L357,190 L357,188 L361,185 L362,180 L361,171 L360,169 L358,169 L352,165 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['TO']['name'] }}: {{ $dsMapTooltips['TO']['total'] }} {{ $dsMapTooltips['TO']['unit'] }}; {{ $dsMapTooltips['TO']['percentage'] }} da base"
              x-on:mouseenter="active = 'TO'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'TO'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'TO' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'TO')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'TO')"
              wire:click="openExplorer('state', 'TO')">
            <title>{{ $dsMapTooltips['TO']['name'] }}: {{ $dsMapTooltips['TO']['total'] }} {{ $dsMapTooltips['TO']['unit'] }} ({{ $dsMapTooltips['TO']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="AL" d="M510,220 L507,220 L505,217 L499,221 L497,224 L487,223 L483,218 L482,219 L480,218 L477,222 L489,229 L494,234 L496,234 L503,227 L506,226 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['AL']['name'] }}: {{ $dsMapTooltips['AL']['total'] }} {{ $dsMapTooltips['AL']['unit'] }}; {{ $dsMapTooltips['AL']['percentage'] }} da base"
              x-on:mouseenter="active = 'AL'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'AL'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'AL' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'AL')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'AL')"
              wire:click="openExplorer('state', 'AL')">
            <title>{{ $dsMapTooltips['AL']['name'] }}: {{ $dsMapTooltips['AL']['total'] }} {{ $dsMapTooltips['AL']['unit'] }} ({{ $dsMapTooltips['AL']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="RO" d="M163,201 L160,204 L158,204 L160,207 L157,209 L157,211 L155,213 L147,214 L147,217 L145,220 L143,220 L142,218 L140,220 L143,222 L142,223 L143,229 L141,232 L144,236 L142,239 L142,242 L147,249 L154,253 L154,255 L155,254 L156,255 L163,254 L165,257 L169,256 L173,261 L176,262 L181,261 L186,267 L187,266 L189,267 L195,266 L198,268 L201,265 L201,263 L207,254 L207,250 L204,246 L204,243 L207,240 L201,238 L189,238 L186,234 L188,230 L186,227 L187,225 L186,220 L187,219 L185,216 L186,214 L185,208 L183,210 L181,210 L176,206 L176,204 L174,204 L172,201 L170,201 L168,199 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['RO']['name'] }}: {{ $dsMapTooltips['RO']['total'] }} {{ $dsMapTooltips['RO']['unit'] }}; {{ $dsMapTooltips['RO']['percentage'] }} da base"
              x-on:mouseenter="active = 'RO'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'RO'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'RO' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'RO')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'RO')"
              wire:click="openExplorer('state', 'RO')">
            <title>{{ $dsMapTooltips['RO']['name'] }}: {{ $dsMapTooltips['RO']['total'] }} {{ $dsMapTooltips['RO']['unit'] }} ({{ $dsMapTooltips['RO']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="SE" d="M478,225 L482,232 L481,234 L482,235 L477,241 L475,240 L477,242 L478,248 L481,248 L490,238 L494,237 L492,235 L490,235 L485,229 L481,228 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['SE']['name'] }}: {{ $dsMapTooltips['SE']['total'] }} {{ $dsMapTooltips['SE']['unit'] }}; {{ $dsMapTooltips['SE']['percentage'] }} da base"
              x-on:mouseenter="active = 'SE'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'SE'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'SE' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'SE')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'SE')"
              wire:click="openExplorer('state', 'SE')">
            <title>{{ $dsMapTooltips['SE']['name'] }}: {{ $dsMapTooltips['SE']['total'] }} {{ $dsMapTooltips['SE']['unit'] }} ({{ $dsMapTooltips['SE']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="BA" d="M383,229 L380,233 L377,234 L377,236 L373,240 L373,242 L378,244 L379,249 L377,252 L375,252 L378,256 L378,263 L377,264 L378,267 L375,272 L375,274 L379,280 L379,286 L377,287 L380,288 L385,283 L389,282 L395,277 L401,277 L405,280 L404,284 L407,285 L410,282 L412,282 L423,291 L428,291 L434,296 L435,299 L439,297 L448,302 L451,306 L446,312 L446,314 L443,315 L442,319 L447,326 L446,329 L449,331 L452,327 L455,326 L454,319 L456,317 L457,309 L460,303 L458,287 L461,282 L461,280 L459,278 L461,275 L459,272 L465,262 L468,262 L470,265 L480,251 L476,250 L474,242 L472,241 L472,239 L474,237 L477,238 L479,235 L479,232 L475,226 L476,224 L473,222 L473,219 L470,217 L469,218 L467,216 L467,214 L464,214 L461,211 L457,213 L454,218 L450,218 L448,223 L445,223 L442,221 L443,218 L440,216 L441,212 L439,210 L430,219 L425,218 L421,222 L419,222 L415,219 L408,219 L407,220 L409,225 L401,235 L398,234 L392,238 L386,235 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['BA']['name'] }}: {{ $dsMapTooltips['BA']['total'] }} {{ $dsMapTooltips['BA']['unit'] }}; {{ $dsMapTooltips['BA']['percentage'] }} da base"
              x-on:mouseenter="active = 'BA'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'BA'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'BA' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'BA')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'BA')"
              wire:click="openExplorer('state', 'BA')">
            <title>{{ $dsMapTooltips['BA']['name'] }}: {{ $dsMapTooltips['BA']['total'] }} {{ $dsMapTooltips['BA']['unit'] }} ({{ $dsMapTooltips['BA']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="MT" d="M188,209 L189,210 L188,216 L190,218 L189,221 L190,233 L189,234 L191,236 L192,235 L202,235 L206,236 L209,239 L207,246 L210,250 L210,255 L206,260 L203,267 L200,269 L204,273 L203,278 L206,281 L206,287 L203,289 L207,293 L207,300 L228,301 L230,303 L229,306 L227,307 L229,310 L228,312 L231,315 L234,315 L239,320 L243,319 L247,312 L251,313 L255,310 L260,312 L266,317 L269,317 L272,314 L278,317 L282,312 L285,312 L286,318 L282,322 L289,322 L288,318 L290,316 L289,313 L290,310 L296,306 L295,301 L301,295 L305,294 L307,288 L310,285 L313,285 L316,282 L315,277 L317,275 L316,270 L320,265 L320,262 L322,259 L319,258 L320,254 L320,243 L319,242 L321,237 L321,230 L323,228 L323,225 L326,222 L262,219 L261,218 L250,218 L248,216 L245,217 L242,216 L239,212 L233,208 L232,206 L233,199 L230,192 L229,195 L227,196 L228,201 L226,203 L226,207 L224,209 L222,208 L221,209 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['MT']['name'] }}: {{ $dsMapTooltips['MT']['total'] }} {{ $dsMapTooltips['MT']['unit'] }}; {{ $dsMapTooltips['MT']['percentage'] }} da base"
              x-on:mouseenter="active = 'MT'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'MT'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'MT' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'MT')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'MT')"
              wire:click="openExplorer('state', 'MT')">
            <title>{{ $dsMapTooltips['MT']['name'] }}: {{ $dsMapTooltips['MT']['total'] }} {{ $dsMapTooltips['MT']['unit'] }} ({{ $dsMapTooltips['MT']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="GO" d="M375,261 L372,261 L370,263 L365,263 L361,266 L358,264 L357,266 L354,266 L351,263 L344,266 L342,264 L343,262 L342,259 L336,265 L326,261 L325,259 L323,262 L323,265 L319,270 L320,276 L318,278 L319,281 L315,287 L310,288 L310,291 L307,295 L298,302 L299,306 L295,310 L293,310 L291,320 L296,328 L295,330 L299,330 L306,335 L309,335 L316,339 L319,337 L319,335 L325,330 L330,331 L332,329 L335,330 L340,326 L345,328 L350,327 L353,330 L359,324 L358,320 L356,319 L356,317 L359,313 L359,310 L357,308 L359,301 L350,301 L348,299 L348,293 L351,290 L360,290 L363,292 L362,298 L365,298 L366,296 L364,294 L365,291 L364,289 L369,286 L370,283 L373,283 L376,285 L376,280 L372,275 L372,271 L375,267 L374,265 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['GO']['name'] }}: {{ $dsMapTooltips['GO']['total'] }} {{ $dsMapTooltips['GO']['unit'] }}; {{ $dsMapTooltips['GO']['percentage'] }} da base"
              x-on:mouseenter="active = 'GO'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'GO'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'GO' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'GO')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'GO')"
              wire:click="openExplorer('state', 'GO')">
            <title>{{ $dsMapTooltips['GO']['name'] }}: {{ $dsMapTooltips['GO']['total'] }} {{ $dsMapTooltips['GO']['unit'] }} ({{ $dsMapTooltips['GO']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="MG" d="M446,304 L442,303 L439,300 L434,302 L428,294 L423,294 L419,290 L414,288 L412,285 L407,288 L404,287 L401,284 L402,280 L398,279 L389,285 L387,285 L381,291 L377,291 L375,288 L371,286 L370,289 L367,289 L368,290 L367,293 L369,295 L368,298 L365,301 L361,302 L360,307 L362,310 L362,313 L359,317 L361,320 L361,326 L356,329 L353,333 L349,330 L345,331 L342,329 L339,329 L335,334 L333,332 L330,334 L325,333 L323,334 L322,339 L318,340 L316,347 L318,347 L322,344 L328,347 L336,347 L338,350 L337,351 L357,347 L362,353 L361,362 L363,366 L365,365 L369,368 L366,379 L369,381 L370,384 L384,381 L389,378 L393,379 L396,377 L404,377 L405,378 L414,373 L416,366 L422,361 L422,356 L427,354 L429,352 L429,349 L434,343 L429,339 L433,335 L431,334 L431,331 L439,325 L441,325 L443,327 L444,326 L439,320 L440,316 L439,315 L448,307 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['MG']['name'] }}: {{ $dsMapTooltips['MG']['total'] }} {{ $dsMapTooltips['MG']['unit'] }}; {{ $dsMapTooltips['MG']['percentage'] }} da base"
              x-on:mouseenter="active = 'MG'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'MG'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'MG' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'MG')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'MG')"
              wire:click="openExplorer('state', 'MG')">
            <title>{{ $dsMapTooltips['MG']['name'] }}: {{ $dsMapTooltips['MG']['total'] }} {{ $dsMapTooltips['MG']['unit'] }} ({{ $dsMapTooltips['MG']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="ES" d="M440,328 L438,330 L436,330 L434,333 L436,335 L435,336 L436,338 L432,339 L434,339 L436,341 L436,345 L432,350 L432,353 L428,357 L424,358 L425,366 L432,367 L432,365 L441,356 L442,352 L445,350 L448,344 L447,343 L448,334 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['ES']['name'] }}: {{ $dsMapTooltips['ES']['total'] }} {{ $dsMapTooltips['ES']['unit'] }}; {{ $dsMapTooltips['ES']['percentage'] }} da base"
              x-on:mouseenter="active = 'ES'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'ES'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'ES' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'ES')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'ES')"
              wire:click="openExplorer('state', 'ES')">
            <title>{{ $dsMapTooltips['ES']['name'] }}: {{ $dsMapTooltips['ES']['total'] }} {{ $dsMapTooltips['ES']['unit'] }} ({{ $dsMapTooltips['ES']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="MS" d="M254,313 L252,315 L249,315 L243,322 L241,323 L238,322 L240,325 L240,328 L238,330 L237,336 L233,342 L233,344 L235,346 L235,349 L233,352 L236,359 L235,373 L237,373 L239,375 L243,374 L247,376 L250,373 L252,373 L254,375 L259,376 L263,381 L262,385 L264,387 L265,396 L268,397 L271,394 L273,394 L276,397 L279,397 L278,393 L283,389 L284,384 L300,372 L303,366 L305,365 L304,362 L306,360 L306,356 L310,352 L315,351 L313,347 L315,342 L309,338 L306,338 L299,333 L294,333 L292,331 L293,329 L290,327 L291,325 L290,324 L289,325 L288,324 L287,325 L280,324 L279,321 L283,317 L283,315 L278,320 L276,320 L273,317 L266,320 L260,315 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['MS']['name'] }}: {{ $dsMapTooltips['MS']['total'] }} {{ $dsMapTooltips['MS']['unit'] }}; {{ $dsMapTooltips['MS']['percentage'] }} da base"
              x-on:mouseenter="active = 'MS'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'MS'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'MS' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'MS')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'MS')"
              wire:click="openExplorer('state', 'MS')">
            <title>{{ $dsMapTooltips['MS']['name'] }}: {{ $dsMapTooltips['MS']['total'] }} {{ $dsMapTooltips['MS']['unit'] }} ({{ $dsMapTooltips['MS']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="SP" d="M293,380 L298,379 L300,381 L302,379 L304,379 L307,381 L309,380 L314,381 L317,384 L324,383 L330,387 L332,390 L331,394 L332,398 L334,398 L336,402 L335,407 L342,406 L344,408 L343,411 L344,410 L347,414 L346,412 L352,407 L356,407 L358,404 L366,398 L373,396 L376,397 L378,394 L385,392 L384,389 L387,386 L392,384 L388,385 L385,383 L381,385 L377,385 L375,387 L373,386 L369,387 L363,379 L366,368 L363,369 L360,368 L360,365 L357,360 L359,358 L357,356 L359,353 L356,350 L355,352 L348,351 L342,353 L341,356 L339,356 L338,354 L336,356 L333,351 L335,350 L328,350 L324,349 L322,347 L318,350 L317,353 L312,354 L309,357 L309,360 L307,362 L307,366 L303,372 L297,378 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['SP']['name'] }}: {{ $dsMapTooltips['SP']['total'] }} {{ $dsMapTooltips['SP']['unit'] }}; {{ $dsMapTooltips['SP']['percentage'] }} da base"
              x-on:mouseenter="active = 'SP'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'SP'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'SP' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'SP')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'SP')"
              wire:click="openExplorer('state', 'SP')">
            <title>{{ $dsMapTooltips['SP']['name'] }}: {{ $dsMapTooltips['SP']['total'] }} {{ $dsMapTooltips['SP']['unit'] }} ({{ $dsMapTooltips['SP']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="RJ" d="M430,370 L423,368 L422,364 L419,366 L417,374 L414,377 L409,378 L405,381 L403,379 L398,379 L394,382 L394,386 L392,387 L393,388 L395,386 L398,386 L400,389 L401,388 L408,389 L415,388 L417,389 L417,385 L421,381 L429,378 L428,373 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['RJ']['name'] }}: {{ $dsMapTooltips['RJ']['total'] }} {{ $dsMapTooltips['RJ']['unit'] }}; {{ $dsMapTooltips['RJ']['percentage'] }} da base"
              x-on:mouseenter="active = 'RJ'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'RJ'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'RJ' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'RJ')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'RJ')"
              wire:click="openExplorer('state', 'RJ')">
            <title>{{ $dsMapTooltips['RJ']['name'] }}: {{ $dsMapTooltips['RJ']['total'] }} {{ $dsMapTooltips['RJ']['unit'] }} ({{ $dsMapTooltips['RJ']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="PR" d="M274,415 L275,417 L279,415 L282,418 L285,425 L286,424 L288,424 L290,426 L295,425 L298,427 L300,426 L303,429 L308,429 L311,426 L316,425 L319,422 L322,424 L325,422 L329,425 L332,425 L334,422 L338,422 L340,420 L342,413 L340,412 L341,409 L335,410 L332,407 L333,401 L329,398 L330,396 L328,395 L329,390 L326,389 L324,386 L317,387 L314,384 L310,383 L307,384 L304,382 L301,383 L291,382 L290,384 L288,384 L286,386 L286,389 L281,393 L281,398 L279,400 L277,400 L278,401 L277,405 L278,408 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['PR']['name'] }}: {{ $dsMapTooltips['PR']['total'] }} {{ $dsMapTooltips['PR']['unit'] }}; {{ $dsMapTooltips['PR']['percentage'] }} da base"
              x-on:mouseenter="active = 'PR'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'PR'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'PR' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'PR')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'PR')"
              wire:click="openExplorer('state', 'PR')">
            <title>{{ $dsMapTooltips['PR']['name'] }}: {{ $dsMapTooltips['PR']['total'] }} {{ $dsMapTooltips['PR']['unit'] }} ({{ $dsMapTooltips['PR']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="DF" d="M351,294 L352,299 L359,299 L360,298 L360,293 L352,293 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['DF']['name'] }}: {{ $dsMapTooltips['DF']['total'] }} {{ $dsMapTooltips['DF']['unit'] }}; {{ $dsMapTooltips['DF']['percentage'] }} da base"
              x-on:mouseenter="active = 'DF'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'DF'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'DF' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'DF')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'DF')"
              wire:click="openExplorer('state', 'DF')">
            <title>{{ $dsMapTooltips['DF']['name'] }}: {{ $dsMapTooltips['DF']['total'] }} {{ $dsMapTooltips['DF']['unit'] }} ({{ $dsMapTooltips['DF']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="SC" d="M285,428 L284,437 L287,434 L292,437 L293,436 L299,437 L304,440 L309,441 L314,445 L318,452 L321,452 L324,454 L327,453 L329,455 L328,458 L326,459 L326,462 L336,455 L338,452 L338,449 L341,446 L340,444 L341,443 L338,441 L340,438 L338,436 L339,425 L335,425 L330,429 L325,425 L322,427 L319,425 L316,428 L311,429 L312,431 L310,433 L302,432 L300,429 L295,428 L290,429 L288,427 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['SC']['name'] }}: {{ $dsMapTooltips['SC']['total'] }} {{ $dsMapTooltips['SC']['unit'] }}; {{ $dsMapTooltips['SC']['percentage'] }} da base"
              x-on:mouseenter="active = 'SC'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'SC'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'SC' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'SC')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'SC')"
              wire:click="openExplorer('state', 'SC')">
            <title>{{ $dsMapTooltips['SC']['name'] }}: {{ $dsMapTooltips['SC']['total'] }} {{ $dsMapTooltips['SC']['unit'] }} ({{ $dsMapTooltips['SC']['percentage'] }} da base)</title>
        </path>
        <path class="ds-v7-state" data-uf="RS" d="M282,438 L277,442 L272,443 L268,448 L266,447 L265,450 L262,451 L258,455 L257,458 L255,458 L255,461 L244,472 L242,476 L246,472 L248,472 L255,479 L258,480 L260,483 L261,481 L263,481 L267,487 L274,489 L278,494 L281,495 L285,499 L286,502 L289,503 L291,502 L292,499 L296,502 L298,500 L297,495 L299,493 L300,489 L303,488 L308,481 L309,478 L307,475 L310,472 L313,476 L316,473 L319,476 L316,483 L311,488 L318,480 L323,469 L323,466 L325,465 L323,466 L321,464 L321,462 L323,461 L323,459 L326,456 L317,455 L311,446 L299,440 L295,441 L293,439 L291,440 L286,438 L285,439 Z"
              role="button" tabindex="0"
              aria-label="{{ $dsMapTooltips['RS']['name'] }}: {{ $dsMapTooltips['RS']['total'] }} {{ $dsMapTooltips['RS']['unit'] }}; {{ $dsMapTooltips['RS']['percentage'] }} da base"
              x-on:mouseenter="active = 'RS'; track($event)"
              x-on:mousemove="track($event)"
              x-on:focus="active = 'RS'"
              x-on:blur="active = null"
              x-bind:class="{ 'is-active': active === 'RS' }"
              x-on:keydown.enter.prevent="$wire.openExplorer('state', 'RS')"
              x-on:keydown.space.prevent="$wire.openExplorer('state', 'RS')"
              wire:click="openExplorer('state', 'RS')">
            <title>{{ $dsMapTooltips['RS']['name'] }}: {{ $dsMapTooltips['RS']['total'] }} {{ $dsMapTooltips['RS']['unit'] }} ({{ $dsMapTooltips['RS']['percentage'] }} da base)</title>
        </path>
        <circle cx="356" cy="296" r="6" class="ds-v7-df-target"
                role="button" tabindex="0"
                aria-label="Distrito Federal: {{ $dsMapTooltips['DF']['total'] }} {{ $dsMapTooltips['DF']['unit'] }}; {{ $dsMapTooltips['DF']['percentage'] }} da base"
                x-on:mouseenter="active = 'DF'; track($event)"
                x-on:mousemove="track($event)"
                x-on:focus="active = 'DF'"
                x-on:blur="active = null"
                x-on:keydown.enter.prevent="$wire.openExplorer('state', 'DF')"
                x-on:keydown.space.prevent="$wire.openExplorer('state', 'DF')"
                wire:click="openExplorer('state', 'DF')">
            <title>Distrito Federal: {{ $dsMapTooltips['DF']['total'] }} {{ $dsMapTooltips['DF']['unit'] }} ({{ $dsMapTooltips['DF']['percentage'] }} da base)</title>
        </circle>
    </svg>
    <div class="ds-v7-map-tooltip" x-show="active !== null" x-cloak
         x-bind:style="{ left: tipX + 'px', top: tipY + 'px' }" aria-hidden="true">
        <strong x-text="active ? states[active].name : ''"></strong>
        <span><b x-text="active ? states[active].total : ''"></b> <span x-text="active ? states[active].unit : ''"></span></span>
        <span x-text="active ? states[active].percentage + ' da base' : ''"></span>
    </div>
</div>
