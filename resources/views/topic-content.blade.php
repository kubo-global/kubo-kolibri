<div class="kolibri-topic-content">
    @if($exercises->isNotEmpty())
        <div class="kolibri-exercises">
            <h3>Exercises</h3>
            @foreach($exercises as $item)
                <div class="kolibri-content-card" data-node-id="{{ $item['map']->kolibri_node_id }}">
                    <h4>{{ $item['node']['title'] ?? 'Exercise' }}</h4>
                    @if(!empty($item['node']['description']))
                        <p>{{ Str::limit($item['node']['description'], 120) }}</p>
                    @endif
                    <a href="{{ route('kolibri.embed', $item['map']->kolibri_node_id) }}"
                       class="kolibri-launch-btn">
                        Start Exercise
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    @if($videos->isNotEmpty())
        <div class="kolibri-videos">
            <h3>Videos</h3>
            @foreach($videos as $item)
                <div class="kolibri-content-card" data-node-id="{{ $item['map']->kolibri_node_id }}">
                    <h4>{{ $item['node']['title'] ?? 'Video' }}</h4>
                    @if(!empty($item['node']['description']))
                        <p>{{ Str::limit($item['node']['description'], 120) }}</p>
                    @endif
                    <a href="{{ route('kolibri.embed', $item['map']->kolibri_node_id) }}"
                       class="kolibri-launch-btn">
                        Watch Video
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    @if($exercises->isEmpty() && $videos->isEmpty())
        <p>No content mapped to this topic yet.</p>
    @endif
</div>
