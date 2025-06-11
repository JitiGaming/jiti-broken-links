jQuery(function($){
    let posts = [];
    let current = 0;
    let with_issue = 0;

    $('#jiti-bl-start').on('click', function() {
        $('#jiti-bl-progress').text('Préparation du scan…\n');
        with_issue = 0;
        current = 0;
        let link_scope = $('#link_scope').val();
        let link_status = $('#link_status').val();

        $.post(JitiBrokenLinks.ajaxurl, {
            action: 'jiti_bl_get_posts',
            nonce: JitiBrokenLinks.nonce,
            link_scope: link_scope
        }, function(res){
            if(!res.success || !res.data.length){
                $('#jiti-bl-progress').append('Aucun article trouvé.\n');
                return;
            }
            posts = res.data;
            scanNext();
        });
        
        function scanNext(){
            if(current >= posts.length){
                $('#jiti-bl-progress').append('\n' + with_issue + ' article' + (with_issue>1?'s':'') + ' avec lien(s) problématique(s).\n');
                return;
            }
            let post_id = posts[current];
            $.post(JitiBrokenLinks.ajaxurl, {
                action: 'jiti_bl_scan_post',
                nonce: JitiBrokenLinks.nonce,
                post_id: post_id,
                link_scope: link_scope,
                link_status: link_status
            }, function(res){
                if(res.success){
                    res.data.lines.forEach(function(line){
                        $('#jiti-bl-progress').append(line + '\n');
                    });
                    if(res.data.has_issue) with_issue++;
                }
                current++;
                setTimeout(scanNext, 5000);
            });
        }
    });
});
